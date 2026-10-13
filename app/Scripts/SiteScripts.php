<?php

namespace App\Scripts;

use App\Models\Certificate;
use App\Models\Database;
use App\Models\Deployment;
use App\Models\Site;

class SiteScripts
{
    /** Create the directory structure, a placeholder page and the Nginx vhost. Runs as root. */
    public static function create(Site $site): string
    {
        $user = $site->system_user;
        $path = $site->path();
        $placeholderDir = $site->zero_downtime ? $path.'/releases/000000-placeholder' : $path;
        $webDir = rtrim($placeholderDir.'/'.trim($site->web_directory ?? '', '/'), '/');

        $placeholder = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>{$site->domain}</title>
<style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;height:100vh;margin:0;background:#0f172a;color:#e2e8f0}</style>
</head><body><div><h1>{$site->domain}</h1><p>This site is managed by Deploi. Install a repository to deploy your application.</p></div></body></html>
HTML;

        $script = 'mkdir -p '.Bash::q($webDir)."\n";

        if ($site->project_type !== 'nodejs') {
            $script .= Bash::writeFile($webDir.'/index.html', $placeholder);
        }

        if ($site->zero_downtime) {
            $script .= 'mkdir -p '.Bash::q($path.'/shared')."\n";
            $script .= 'ln -sfn '.Bash::q($placeholderDir).' '.Bash::q($path.'/current')."\n";
        }

        $script .= 'chown -R '.Bash::q("{$user}:{$user}").' '.Bash::q($path)."\n";
        $script .= self::nginxConfigFor($site, null);

        return $script;
    }

    /** Write the vhost and reload Nginx, rolling back if the config doesn't validate. */
    public static function nginxConfigFor(Site $site, ?Certificate $certificate): string
    {
        $available = "/etc/nginx/sites-available/{$site->domain}";
        $enabled = "/etc/nginx/sites-enabled/{$site->domain}";
        $config = NginxConfig::render($site, $certificate);

        return 'if [ -f '.Bash::q($available).' ]; then cp '.Bash::q($available).' /tmp/deploi-nginx-backup; fi'."\n"
            .Bash::writeFile($available, $config)
            .'ln -sf '.Bash::q($available).' '.Bash::q($enabled)."\n"
            .<<<BASH
if ! nginx -t; then
    echo "Nginx config test failed - rolling back."
    if [ -f /tmp/deploi-nginx-backup ]; then cp /tmp/deploi-nginx-backup {$available}; else rm -f {$enabled}; fi
    exit 1
fi
systemctl reload nginx
echo "Nginx configuration updated for {$site->domain}."

BASH;
    }

    /**
     * After changing a site's primary domain: write the vhost under the new name first (tested,
     * rolled back on failure), and only then remove the old file - the site is never unconfigured.
     */
    public static function renameVhost(Site $site, string $oldDomain): string
    {
        $old = Bash::q($oldDomain);

        return self::nginxConfigFor($site, $site->activeCertificate())
            ."rm -f /etc/nginx/sites-enabled/{$old} /etc/nginx/sites-available/{$old}\n"
            ."nginx -t\nsystemctl reload nginx\n"
            ."echo 'Renamed {$oldDomain} to {$site->domain}.'\n";
    }

    public static function delete(Site $site): string
    {
        $q = Bash::q($site->domain);
        $certs = $site->certificates->map(fn ($c) => $c->type === 'letsencrypt'
            ? 'certbot delete --cert-name '.Bash::q($c->name())." --non-interactive 2>/dev/null || true\n"
            : 'rm -rf '.Bash::q($c->path())."\n"
        )->implode('');
        $workers = $site->queueWorkers->map(fn ($w) => "supervisorctl stop '{$w->programName()}:*' || true\nrm -f /etc/supervisor/conf.d/{$w->programName()}.conf\n")->implode('');

        return <<<BASH
rm -f /etc/nginx/sites-enabled/{$q} /etc/nginx/sites-available/{$q}
nginx -t && systemctl reload nginx
{$workers}supervisorctl reread >/dev/null && supervisorctl update >/dev/null || true
rm -rf {$site->path()}
{$certs}echo "Site removed."
BASH;
    }

    /**
     * Clone the repository and prepare .env. Runs as the site's system user; the first
     * deployment is triggered by the task callback afterwards.
     */
    public static function installRepository(Site $site, ?Database $database = null): string
    {
        $path = Bash::q($site->path());
        $repo = Bash::q($site->gitUrl());
        $branch = Bash::q($site->branch);
        $envPath = $site->zero_downtime ? $site->path().'/shared/.env' : $site->path().'/.env';

        $script = <<<BASH
export GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=accept-new"

echo "Checking repository access..."
git ls-remote --heads {$repo} {$branch} | grep -q . || { echo "Branch {$site->branch} not found or repository not accessible."; exit 1; }

BASH;

        if ($site->zero_downtime) {
            $script .= <<<BASH
mkdir -p {$path}/releases {$path}/shared
TMP=\$(mktemp -d)
git clone --depth 1 --branch {$branch} {$repo} \$TMP/repo
if [ ! -f {$path}/shared/.env ] && [ -f \$TMP/repo/.env.example ]; then
    cp \$TMP/repo/.env.example {$path}/shared/.env
fi
rm -rf \$TMP

BASH;
        } else {
            $script .= <<<BASH
cd {$path}
find . -mindepth 1 -maxdepth 1 -exec rm -rf {} +
git clone --branch {$branch} {$repo} .
if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

BASH;
        }

        if ($site->isLaravel()) {
            $env = [
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_URL' => 'http://'.$site->domain,
                'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            ];

            if ($database) {
                $server = $site->server;
                $env += [
                    'DB_CONNECTION' => $server->database_type === 'postgresql' ? 'pgsql' : ($server->database_type === 'mariadb' ? 'mariadb' : 'mysql'),
                    'DB_HOST' => '127.0.0.1',
                    'DB_PORT' => $server->database_type === 'postgresql' ? '5432' : '3306',
                    'DB_DATABASE' => $database->name,
                    'DB_USERNAME' => $server->system_user,
                    'DB_PASSWORD' => $server->database_password,
                ];
            }

            $script .= self::setEnvValues($envPath, $env, onlyIfEmpty: ['APP_KEY']);
        }

        return $script."echo 'Repository installed.'\n";
    }

    /** Set KEY=value pairs in a .env file, appending keys that don't exist yet. */
    public static function setEnvValues(string $envPath, array $values, array $onlyIfEmpty = []): string
    {
        $env = Bash::q($envPath);
        $script = "touch {$env}\n";

        foreach ($values as $key => $value) {
            $line = Bash::q("{$key}={$value}");
            $set = "if grep -q '^{$key}=' {$env}; then sed -i \"s|^{$key}=.*|\"{$line}\"|\" {$env}; else echo {$line} >> {$env}; fi";

            $script .= in_array($key, $onlyIfEmpty, true)
                ? "if ! grep -q '^{$key}=.\\+' {$env}; then {$set}; fi\n"
                : "{$set}\n";
        }

        return $script;
    }

    /** The full deployment run: wraps the user's deploy script with release handling. Runs as the system user. */
    public static function deploy(Site $site, Deployment $deployment): string
    {
        $path = $site->path();
        $php = 'php'.$site->phpVersion();
        $env = [
            'DEPLOI_SITE_PATH' => $path,
            'DEPLOI_DOMAIN' => $site->domain,
            'DEPLOI_BRANCH' => $site->branch,
            'DEPLOI_REPOSITORY' => $site->gitUrl(),
            'DEPLOI_PHP' => $php,
            'DEPLOI_PHP_FPM' => $php.'-fpm',
            'DEPLOI_COMPOSER' => $php.' /usr/local/bin/composer',
            'DEPLOI_DEPLOYMENT_ID' => (string) $deployment->id,
        ];

        $exports = collect($env)->map(fn ($v, $k) => "export {$k}=".Bash::q($v))->implode("\n");
        $userScript = str_replace("\r\n", "\n", $site->deploy_script ?: $site->defaultDeployScript());
        $commit = 'echo "##DEPLOI_COMMIT:$(git -C "$DEPLOI_RELEASE_PATH" log -1 --pretty=format:\'%H|%an|%s\')##"';

        if (! $site->zero_downtime) {
            return <<<BASH
{$exports}
export DEPLOI_RELEASE_PATH="\$DEPLOI_SITE_PATH"
export GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=accept-new"
cd "\$DEPLOI_SITE_PATH"

# ---- Your deploy script ----
{$userScript}
# ----------------------------

{$commit}
BASH;
        }

        $keep = max(1, (int) $site->keep_releases);
        $pruneFrom = $keep + 1;
        $sharedStorage = $site->isLaravel() ? <<<'BASH'
if [ -d storage ]; then
    if [ ! -d "$DEPLOI_SITE_PATH/shared/storage" ]; then
        mv storage "$DEPLOI_SITE_PATH/shared/storage"
    else
        rm -rf storage
    fi
    ln -sfn "$DEPLOI_SITE_PATH/shared/storage" storage
fi
BASH : '';

        return <<<BASH
{$exports}
export GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=accept-new"
RELEASE=\$(date +%Y%m%d%H%M%S)
export DEPLOI_RELEASE_PATH="\$DEPLOI_SITE_PATH/releases/\$RELEASE"
echo "##DEPLOI_RELEASE:\$RELEASE##"

mkdir -p "\$DEPLOI_SITE_PATH/releases" "\$DEPLOI_SITE_PATH/shared"
echo "Cloning \$DEPLOI_BRANCH into release \$RELEASE..."
git clone --depth 1 --branch "\$DEPLOI_BRANCH" "\$DEPLOI_REPOSITORY" "\$DEPLOI_RELEASE_PATH"
cd "\$DEPLOI_RELEASE_PATH"

# Shared files survive between releases
if [ -f "\$DEPLOI_SITE_PATH/shared/.env" ]; then
    ln -sfn "\$DEPLOI_SITE_PATH/shared/.env" .env
fi
{$sharedStorage}

# Remove the new release if anything below fails
trap 'echo "Deployment failed - removing release \$RELEASE"; rm -rf "\$DEPLOI_RELEASE_PATH"' ERR

# ---- Your deploy script ----
{$userScript}
# ----------------------------

trap - ERR
cd "\$DEPLOI_RELEASE_PATH"
{$commit}

echo "Activating release \$RELEASE"
ln -sfn "\$DEPLOI_RELEASE_PATH" "\$DEPLOI_SITE_PATH/current-tmp"
mv -Tf "\$DEPLOI_SITE_PATH/current-tmp" "\$DEPLOI_SITE_PATH/current"

# Keep the {$keep} most recent releases
cd "\$DEPLOI_SITE_PATH/releases"
ls -1t | tail -n +{$pruneFrom} | xargs -r rm -rf
echo "Release \$RELEASE is live."
BASH;
    }

    /**
     * An ad-hoc command run from the site's Commands tab, in the live directory as the site user.
     * "php" and "composer" resolve to the site's own PHP version rather than the server CLI default.
     */
    public static function command(Site $site, string $command): string
    {
        $php = 'php'.$site->phpVersion();
        $dir = Bash::q($site->currentPath());
        $env = collect([
            'DEPLOI_SITE_PATH' => $site->path(),
            'DEPLOI_DOMAIN' => $site->domain,
            'DEPLOI_PHP' => $php,
            'DEPLOI_COMPOSER' => $php.' /usr/local/bin/composer',
        ])->map(fn ($v, $k) => "export {$k}=".Bash::q($v))->implode("\n");

        $shims = $site->usesPhp() ? <<<BASH
if command -v {$php} >/dev/null 2>&1; then
    php() { {$php} "\$@"; }
    composer() { {$php} /usr/local/bin/composer "\$@"; }
fi

BASH : '';

        return <<<BASH
{$env}
cd {$dir}
{$shims}
# ---- Command ----
{$command}
BASH;
    }

    /** Roll a zero-downtime site back to a previous release. */
    public static function rollback(Site $site, string $release): string
    {
        $path = Bash::q($site->path());
        $rel = Bash::q($release);

        return <<<BASH
test -d {$path}/releases/{$rel} || { echo "Release {$release} no longer exists on the server."; exit 1; }
ln -sfn {$path}/releases/{$rel} {$path}/current-tmp
mv -Tf {$path}/current-tmp {$path}/current
sudo -n /usr/sbin/service php{$site->phpVersion()}-fpm reload || true
echo "Rolled back to {$release}."
BASH;
    }

    public static function removeRepository(Site $site): string
    {
        $path = Bash::q($site->path());

        return <<<BASH
cd {$path}
find . -mindepth 1 -maxdepth 1 -exec rm -rf {} +
echo "Repository removed."
BASH;
    }

    public static function envPath(Site $site): string
    {
        return $site->zero_downtime ? $site->path().'/shared/.env' : $site->path().'/.env';
    }

    public static function logPaths(Site $site): array
    {
        $paths = $site->imported
            ? [$site->webserver() => $site->meta['error_log'] ?? ($site->webserver() === 'apache' ? '/var/log/apache2/error.log' : '/var/log/nginx/error.log')]
            : ['nginx' => "/var/log/nginx/{$site->domain}-error.log"];

        if ($site->isLaravel()) {
            $paths['laravel'] = $site->currentPath().'/storage/logs/laravel.log';
        }

        return $paths;
    }
}
