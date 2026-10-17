<?php

namespace App\Services\Discovery;

use App\Models\Server;
use App\Scripts\Bash;

/**
 * Inspects an existing server over SSH - strictly read-only - and reports what's on it:
 * installed software, Nginx/Apache sites (with their directory, PHP version, SSL and git remote),
 * databases, cron jobs, Supervisor programs, firewall rules and system users.
 */
class ServerDiscovery
{
    public function __construct(protected Server $server) {}

    public function scan(): array
    {
        $ssh = $this->server->ssh();
        $raw = $ssh->runScript(self::scanScript(), 'root', timeout: 90)->output;

        $parsed = self::parseScanOutput($raw);
        $sites = self::buildSites($parsed['files']);

        // Second pass: look inside every site's web root.
        $roots = collect($sites)->pluck('root')->filter()->unique()->values()->all();
        $certs = collect($sites)->pluck('ssl_certificate')->filter()->unique()->values()->all();

        if ($roots || $certs) {
            $dirOutput = $ssh->runScript(self::inspectScript($roots, $certs), 'root', timeout: 90)->output;
            [$dirs, $expiries] = self::parseInspectOutput($dirOutput);
            $sites = self::enrichSites($sites, $dirs, $expiries);
        }

        return self::assemble($parsed, $sites);
    }

    // ------------------------------------------------------------------ Scripts

    public static function scanScript(): string
    {
        return <<<'BASH'
set +e +o pipefail
section() { echo "##DEPLOI_SECTION:$1"; }
dump() { echo "##DEPLOI_FILE:$1:$2"; cat "$2" 2>/dev/null; echo; echo "##DEPLOI_ENDFILE"; }

section info
echo "hostname=$(hostname)"
echo "os=$(. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME")"
command -v nginx >/dev/null 2>&1 && echo "nginx=$(nginx -v 2>&1 | sed 's#.*/##')"
command -v apache2 >/dev/null 2>&1 && echo "apache=$(apache2 -v 2>/dev/null | head -1 | sed 's#.*Apache/##; s# .*##')"
command -v php >/dev/null 2>&1 && echo "php_cli=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)"
echo "php_fpm=$(ls -d /etc/php/*/fpm 2>/dev/null | cut -d/ -f4 | tr '\n' ' ')"
command -v mysqld >/dev/null 2>&1 && echo "mysql=$(mysqld --version 2>/dev/null | head -1)"
command -v psql >/dev/null 2>&1 && echo "postgresql=$(psql --version 2>/dev/null | head -1)"
command -v redis-server >/dev/null 2>&1 && echo "redis=$(redis-server --version 2>/dev/null | sed 's/.*v=\([^ ]*\).*/\1/')"
command -v node >/dev/null 2>&1 && echo "node=$(node -v 2>/dev/null)"
{ [ -x /usr/local/bin/composer ] || command -v composer >/dev/null 2>&1; } && echo "composer=yes"
command -v supervisorctl >/dev/null 2>&1 && echo "supervisor=yes"
command -v certbot >/dev/null 2>&1 && echo "certbot=yes"
if command -v ufw >/dev/null 2>&1; then echo "ufw=$(ufw status 2>/dev/null | head -1 | awk '{print $2}')"; else echo "ufw=missing"; fi

section users
awk -F: '($3 >= 1000 && $3 < 65534) || $1 == "www-data" { print $1 ":" $6 ":" $7 }' /etc/passwd

section keys
awk -F: '($3 >= 1000 && $3 < 65534) || $1 == "www-data" || $1 == "root" { print $1 ":" $6 }' /etc/passwd | while IFS=: read -r u h; do
    for k in id_ed25519.pub id_rsa.pub id_ecdsa.pub; do
        if [ -f "$h/.ssh/$k" ]; then echo "$u=$(head -1 "$h/.ssh/$k")"; break; fi
    done
done

section webconfig
for f in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
    [ -f "$f" ] && dump nginx "$(readlink -f "$f")"
done
for f in /etc/apache2/sites-enabled/*; do
    [ -f "$f" ] && dump apache "$(readlink -f "$f")"
done

section databases
if command -v mysql >/dev/null 2>&1; then mysql -N -e 'SHOW DATABASES' 2>/dev/null | sed 's/^/mysql:/'; fi
if command -v psql >/dev/null 2>&1; then sudo -u postgres psql -Atc "SELECT datname FROM pg_database WHERE NOT datistemplate" 2>/dev/null | sed 's/^/postgresql:/'; fi

section cron
for f in /etc/cron.d/*; do [ -f "$f" ] && dump cron "$f"; done
for u in $(cut -d: -f1 /etc/passwd); do
    c=$(crontab -l -u "$u" 2>/dev/null)
    if [ -n "$c" ]; then echo "##DEPLOI_FILE:crontab:$u"; echo "$c"; echo "##DEPLOI_ENDFILE"; fi
done

section supervisor
for f in /etc/supervisor/conf.d/*.conf; do [ -f "$f" ] && dump supervisor "$f"; done

section firewall
ufw status 2>/dev/null

section end
BASH;
    }

    /** For every web root: who owns it, whether it's Laravel/WordPress, and the git remote/branch. */
    public static function inspectScript(array $roots, array $certs): string
    {
        $script = <<<'BASH'
set +e +o pipefail
inspect() {
    root="$1"
    echo "##DEPLOI_DIR:$root"
    if [ ! -e "$root" ]; then echo "exists=no"; return; fi
    echo "exists=yes"
    echo "owner=$(stat -c %U "$root")"
    echo "realpath=$(readlink -f "$root")"
    for d in "$root" "$(dirname "$root")"; do
        [ -f "$d/artisan" ] && echo "artisan=$d"
        [ -f "$d/wp-config.php" ] && echo "wordpress=$d"
        [ -f "$d/package.json" ] && echo "package=$d"
        if [ -e "$d/.git" ]; then
            echo "git=$d"
            echo "remote=$(git -c safe.directory='*' -C "$d" config --get remote.origin.url 2>/dev/null)"
            echo "branch=$(git -c safe.directory='*' -C "$d" rev-parse --abbrev-ref HEAD 2>/dev/null)"
        fi
    done
}
cert() {
    echo "##DEPLOI_CERT:$1=$(openssl x509 -enddate -noout -in "$1" 2>/dev/null | cut -d= -f2)"
}

BASH;

        foreach ($roots as $root) {
            $script .= 'inspect '.Bash::q($root)."\n";
        }

        foreach ($certs as $cert) {
            $script .= 'cert '.Bash::q($cert)."\n";
        }

        return $script;
    }

    // ------------------------------------------------------------------ Parsing

    /** @return array{info: array, users: array, keys: array, files: array, databases: array, firewall: string} */
    public static function parseScanOutput(string $raw): array
    {
        $result = ['info' => [], 'users' => [], 'keys' => [], 'files' => [], 'databases' => [], 'firewall' => ''];
        $section = null;
        $file = null;
        $buffer = [];

        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if ($file !== null) {
                if ($line === '##DEPLOI_ENDFILE') {
                    $result['files'][] = $file + ['content' => rtrim(implode("\n", $buffer))];
                    $file = null;
                    $buffer = [];
                } else {
                    $buffer[] = $line;
                }

                continue;
            }

            if (str_starts_with($line, '##DEPLOI_SECTION:')) {
                $section = substr($line, 17);

                continue;
            }

            if (str_starts_with($line, '##DEPLOI_FILE:')) {
                [$type, $path] = explode(':', substr($line, 14), 2) + [1 => ''];
                $file = ['type' => $type, 'path' => $path];

                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            match ($section) {
                'info', 'keys' => (function () use ($line, $section, &$result) {
                    [$k, $v] = explode('=', $line, 2) + [1 => ''];
                    $result[$section][trim($k)] = trim($v);
                })(),
                'users' => $result['users'][] = array_combine(['name', 'home', 'shell'], explode(':', $line, 3) + [1 => '', 2 => '']),
                'databases' => $result['databases'][] = $line,
                'firewall' => $result['firewall'] .= $line."\n",
                default => null,
            };
        }

        return $result;
    }

    /** @return array{0: array<string, array>, 1: array<string, string>} directory facts keyed by root, cert expiry keyed by path */
    public static function parseInspectOutput(string $raw): array
    {
        $dirs = [];
        $certs = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (str_starts_with($line, '##DEPLOI_DIR:')) {
                $current = substr($line, 13);
                $dirs[$current] = [];
            } elseif (str_starts_with($line, '##DEPLOI_CERT:')) {
                [$path, $expiry] = explode('=', substr($line, 14), 2) + [1 => ''];
                $certs[$path] = trim($expiry);
            } elseif ($current !== null && str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                // The first value wins (the web root itself is checked before its parent).
                $dirs[$current][$k] ??= trim($v);
            }
        }

        return [$dirs, $certs];
    }

    /** Parse Nginx config text into server blocks. */
    public static function parseNginx(string $config, string $path): array
    {
        $config = preg_replace('/(^|[\s;{}])#[^\n]*/', '$1', $config);
        $blocks = [];

        preg_match_all('/\bserver\s*\{/', $config, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$match, $offset]) {
            $start = $offset + strlen($match);
            $depth = 1;
            $len = strlen($config);

            for ($i = $start; $i < $len && $depth > 0; $i++) {
                if ($config[$i] === '{') {
                    $depth++;
                } elseif ($config[$i] === '}') {
                    $depth--;
                }
            }

            $body = substr($config, $start, $i - $start - 1);
            $blocks[] = self::parseNginxBlock($body, $path);
        }

        return $blocks;
    }

    protected static function parseNginxBlock(string $body, string $path): array
    {
        $directive = fn (string $name) => preg_match('/(?:^|[;{}\s])'.$name.'\s+([^;]+);/m', $body, $m) ? trim($m[1], " \t\n\"'") : null;

        $names = [];
        if (preg_match_all('/(?:^|[;{}\s])server_name\s+([^;]+);/m', $body, $m)) {
            foreach ($m[1] as $list) {
                $names = array_merge($names, preg_split('/\s+/', trim($list)));
            }
        }

        $fastcgi = $directive('fastcgi_pass');
        $proxy = $directive('proxy_pass');

        return [
            'webserver' => 'nginx',
            'config_path' => $path,
            'names' => array_values(array_unique($names)),
            'root' => $directive('root'),
            'ssl' => (bool) preg_match('/listen\s+[^;]*\b(443|ssl)\b/', $body),
            'ssl_certificate' => $directive('ssl_certificate'),
            'php' => $fastcgi !== null,
            'php_version' => $fastcgi && preg_match('/php(\d+\.\d+)/', $fastcgi, $v) ? $v[1] : null,
            'app_port' => $proxy && preg_match('#^https?://(?:127\.0\.0\.1|localhost|\[::1\]):(\d+)#', $proxy, $p) ? (int) $p[1] : null,
            'error_log' => ($log = $directive('error_log')) ? preg_split('/\s+/', $log)[0] : null,
            'redirect_only' => $directive('root') === null && $proxy === null && (bool) preg_match('/return\s+30[1278]/', $body),
        ];
    }

    /** Parse Apache config text into virtual hosts. */
    public static function parseApache(string $config, string $path): array
    {
        $config = preg_replace('/^\s*#.*$/m', '', $config);
        $blocks = [];

        preg_match_all('/<VirtualHost\s+([^>]*)>(.*?)<\/VirtualHost>/si', $config, $vhosts, PREG_SET_ORDER);

        foreach ($vhosts as [, $address, $body]) {
            $directive = fn (string $name) => preg_match('/^\s*'.$name.'\s+(.+)$/mi', $body, $m) ? trim($m[1], " \t\"'") : null;

            $names = array_filter([$directive('ServerName')]);
            if (preg_match_all('/^\s*ServerAlias\s+(.+)$/mi', $body, $m)) {
                foreach ($m[1] as $list) {
                    $names = array_merge($names, preg_split('/\s+/', trim($list)));
                }
            }

            $php = preg_match('/proxy:unix:[^|"]*php(\d+\.\d+)?[^|"]*fpm/i', $body, $phpMatch) || preg_match('/php/i', $directive('SetHandler') ?? '');
            $errorLog = $directive('ErrorLog');

            $blocks[] = [
                'webserver' => 'apache',
                'config_path' => $path,
                'names' => array_values(array_unique($names)),
                'root' => $directive('DocumentRoot'),
                'ssl' => str_contains($address, ':443') || (bool) preg_match('/^\s*SSLEngine\s+on/mi', $body),
                'ssl_certificate' => $directive('SSLCertificateFile'),
                'php' => (bool) $php,
                'php_version' => $phpMatch[1] ?? null,
                'app_port' => preg_match('#ProxyPass\s+/\s+https?://(?:127\.0\.0\.1|localhost):(\d+)#i', $body, $p) ? (int) $p[1] : null,
                'error_log' => $errorLog ? str_replace('${APACHE_LOG_DIR}', '/var/log/apache2', $errorLog) : null,
                'redirect_only' => $directive('DocumentRoot') === null && (bool) preg_match('/^\s*Redirect(Match)?\s+/mi', $body),
            ];
        }

        return $blocks;
    }

    /** Merge server blocks (e.g. the HTTP→HTTPS redirect block + the real one) into one entry per site. */
    public static function buildSites(array $files): array
    {
        $blocks = [];
        foreach ($files as $file) {
            $blocks = array_merge($blocks, match ($file['type']) {
                'nginx' => self::parseNginx($file['content'], $file['path']),
                'apache' => self::parseApache($file['content'], $file['path']),
                default => [],
            });
        }

        $sites = [];
        $sitesPerConfig = [];

        foreach ($blocks as $block) {
            $names = array_values(array_filter($block['names'], fn ($n) => preg_match('/^(\*\.)?[A-Za-z0-9][A-Za-z0-9.-]*$/', $n) && ! in_array($n, ['localhost', '_'], true)));

            if (! $names) {
                continue; // catch-all / default server
            }

            // Group under the first non-wildcard name (or an existing entry that shares any name).
            $primary = collect($names)->first(fn ($n) => ! str_starts_with($n, '*')) ?? $names[0];
            $key = collect($sites)->keys()->first(fn ($k) => in_array($k, $names, true) || array_intersect($names, $sites[$k]['aliases'] ?? []))
                ?? strtolower($primary);

            $site = $sites[$key] ?? [
                'domain' => $key, 'aliases' => [], 'webserver' => $block['webserver'], 'config_path' => $block['config_path'],
                'root' => null, 'ssl' => false, 'ssl_certificate' => null, 'php' => false, 'php_version' => null,
                'app_port' => null, 'error_log' => null,
            ];

            $site['aliases'] = array_values(array_unique(array_diff(array_merge($site['aliases'], array_map('strtolower', $names)), [$key])));
            $site['ssl'] = $site['ssl'] || $block['ssl'];

            foreach (['root', 'ssl_certificate', 'php_version', 'app_port', 'error_log'] as $field) {
                $site[$field] ??= $block[$field];
            }
            $site['php'] = $site['php'] || $block['php'];

            // Prefer the config file of the block that actually serves content.
            if (! $block['redirect_only'] && ($block['root'] || $block['app_port'])) {
                $site['config_path'] = $block['config_path'];
                $site['webserver'] = $block['webserver'];
            }

            $sites[$key] = $site;
            $sitesPerConfig[$block['config_path']][$key] = true;
        }

        foreach ($sites as $key => $site) {
            $sites[$key]['shared_config'] = count($sitesPerConfig[$site['config_path']] ?? []) > 1;
        }

        ksort($sites);

        return $sites;
    }

    /** Use directory facts to work out project type, project directory, owner and git remote. */
    public static function enrichSites(array $sites, array $dirs, array $certExpiries): array
    {
        foreach ($sites as $key => $site) {
            $facts = $site['root'] ? ($dirs[$site['root']] ?? []) : [];
            $root = $site['root'] ? rtrim($site['root'], '/') : null;
            $parent = $root ? dirname($root) : null;

            $type = match (true) {
                isset($facts['artisan']) => 'laravel',
                isset($facts['wordpress']) => 'wordpress',
                $site['app_port'] !== null => 'nodejs',
                $site['php'] => 'php',
                default => 'static',
            };

            // Project directory: Laravel's parent of /public, or the git checkout that contains the web root.
            $directory = $root;
            if (($facts['artisan'] ?? null) === $parent || ($facts['git'] ?? null) === $parent) {
                $directory = $parent;
            }
            $webDirectory = $root && $directory !== $root ? '/'.basename($root) : '/';

            $sites[$key] += [
                'exists' => ($facts['exists'] ?? 'yes') !== 'no',
                'project_type' => $type,
                'directory' => $directory,
                'web_directory' => $webDirectory,
                'owner' => $facts['owner'] ?? null,
                'repository' => isset($facts['remote']) && $facts['remote'] !== '' ? self::stripCredentials($facts['remote']) : null,
                'branch' => isset($facts['branch']) && $facts['branch'] !== 'HEAD' && $facts['branch'] !== '' ? $facts['branch'] : null,
                'is_git' => isset($facts['git']) && $facts['git'] === $directory,
                'ssl_expires' => $site['ssl_certificate'] ? ($certExpiries[$site['ssl_certificate']] ?? null) ?: null : null,
            ];
        }

        return $sites;
    }

    protected static function assemble(array $parsed, array $sites): array
    {
        $info = $parsed['info'];
        $mysql = $info['mysql'] ?? null;

        $databaseType = match (true) {
            $mysql && stripos($mysql, 'mariadb') !== false => 'mariadb',
            (bool) $mysql => 'mysql',
            isset($info['postgresql']) => 'postgresql',
            default => null,
        };

        $systemDatabases = ['information_schema', 'mysql', 'performance_schema', 'sys', 'postgres'];

        $databases = collect($parsed['databases'])
            ->map(fn ($line) => explode(':', $line, 2))
            ->filter(fn ($parts) => count($parts) === 2 && ! in_array($parts[1], $systemDatabases, true))
            ->map(fn ($parts) => ['engine' => $parts[0], 'name' => $parts[1]])
            ->values()->all();

        $files = collect($parsed['files']);

        return [
            'scanned_at' => now()->toIso8601String(),
            'hostname' => $info['hostname'] ?? null,
            'os' => $info['os'] ?? null,
            'software' => array_filter([
                'nginx' => $info['nginx'] ?? null,
                'apache' => $info['apache'] ?? null,
                'php_cli' => $info['php_cli'] ?? null,
                'php_fpm' => array_values(array_filter(explode(' ', $info['php_fpm'] ?? ''))),
                'mysql' => $mysql,
                'postgresql' => $info['postgresql'] ?? null,
                'redis' => $info['redis'] ?? null,
                'node' => $info['node'] ?? null,
                'composer' => isset($info['composer']),
                'supervisor' => isset($info['supervisor']),
                'certbot' => isset($info['certbot']),
            ]),
            'ufw' => $info['ufw'] ?? 'missing',
            'database_type' => $databaseType,
            'database_engines' => array_values(array_filter([
                $mysql ? (stripos($mysql, 'mariadb') !== false ? 'mariadb' : 'mysql') : null,
                isset($info['postgresql']) ? 'postgresql' : null,
            ])),
            'users' => $parsed['users'],
            'keys' => $parsed['keys'],
            'sites' => $sites,
            'databases' => $databases,
            'cron' => $files->whereIn('type', ['cron', 'crontab'])->map(fn ($f) => [
                'source' => $f['type'] === 'crontab' ? "crontab ({$f['path']})" : $f['path'],
                'lines' => array_values(array_filter(explode("\n", $f['content']), fn ($l) => trim($l) !== '' && ! str_starts_with(trim($l), '#') && ! preg_match('/^\s*[A-Z_]+=/', $l))),
            ])->filter(fn ($c) => $c['lines'])->values()->all(),
            'supervisor' => $files->where('type', 'supervisor')->map(fn ($f) => [
                'path' => $f['path'],
                'programs' => self::parseSupervisor($f['content']),
            ])->values()->all(),
            'firewall' => trim($parsed['firewall']),
        ];
    }

    protected static function parseSupervisor(string $content): array
    {
        $programs = [];
        $current = null;

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\s*\[program:([^\]]+)\]/', $line, $m)) {
                $current = $m[1];
                $programs[$current] = ['command' => null, 'user' => null, 'numprocs' => 1];
            } elseif ($current && preg_match('/^\s*(command|user|numprocs)\s*=\s*(.+)$/', $line, $m)) {
                $programs[$current][$m[1]] = trim($m[2]);
            }
        }

        return $programs;
    }

    /** "https://user:token@github.com/a/b.git" → "https://github.com/a/b.git" (never store secrets). */
    public static function stripCredentials(string $url): string
    {
        return preg_replace('#^(https?://)[^/@]+@#', '$1', $url);
    }

    /** Map a git remote to Deploi's repository fields. */
    public static function repositoryFields(?string $remote): array
    {
        if (! $remote) {
            return ['repository_provider' => null, 'repository' => null];
        }

        foreach (['github' => 'github\.com', 'gitlab' => 'gitlab\.com', 'bitbucket' => 'bitbucket\.org'] as $provider => $host) {
            if (preg_match('#'.$host.'[:/](.+?)(?:\.git)?/?$#', $remote, $m)) {
                return ['repository_provider' => $provider, 'repository' => $m[1]];
            }
        }

        return ['repository_provider' => 'custom', 'repository' => $remote];
    }
}
