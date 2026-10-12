<?php

namespace App\Scripts;

use App\Models\Certificate;
use App\Models\Cronjob;
use App\Models\Daemon;
use App\Models\Database;
use App\Models\DatabaseBackup;
use App\Models\DatabaseUser;
use App\Models\FirewallRule;
use App\Models\QueueWorker;
use App\Models\Server;
use App\Models\ServerSshKey;

class ServerScripts
{
    public const LOG_DIR = '/var/log/deploi';

    // ---------------------------------------------------------------- Databases

    protected static function sql(Server $server, string $sql): string
    {
        return $server->database_type === 'postgresql'
            ? Bash::pipeSql($sql, 'sudo -u postgres psql -v ON_ERROR_STOP=1')
            : Bash::pipeSql($sql, 'mysql');
    }

    public static function createDatabase(Database $db): string
    {
        $server = $db->server;

        if ($server->database_type === 'postgresql') {
            return "if ! sudo -u postgres psql -tAc \"SELECT 1 FROM pg_database WHERE datname='{$db->name}'\" | grep -q 1; then\n"
                ."    sudo -u postgres createdb {$db->name}\nfi\necho 'Database {$db->name} ready.'\n";
        }

        return self::sql($server, "CREATE DATABASE IF NOT EXISTS `{$db->name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")
            ."echo 'Database {$db->name} ready.'\n";
    }

    public static function dropDatabase(Database $db): string
    {
        $server = $db->server;

        return $server->database_type === 'postgresql'
            ? "sudo -u postgres dropdb --if-exists {$db->name}\n"
            : self::sql($server, "DROP DATABASE IF EXISTS `{$db->name}`;");
    }

    /** Create or update a user and sync its grants to exactly the given databases. */
    public static function syncDatabaseUser(DatabaseUser $user): string
    {
        $server = $user->server;
        $u = $user->username;
        $p = $user->password;
        $dbs = $user->databases->pluck('name');

        if ($server->database_type === 'postgresql') {
            $sql = "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$u}') THEN CREATE ROLE \"{$u}\" LOGIN PASSWORD '{$p}'; ELSE ALTER ROLE \"{$u}\" WITH LOGIN PASSWORD '{$p}'; END IF; END \$\$;\n";
            $script = self::sql($server, $sql);

            foreach ($server->databases()->pluck('name') as $name) {
                $stmt = $dbs->contains($name)
                    ? "GRANT ALL PRIVILEGES ON DATABASE \"{$name}\" TO \"{$u}\";"
                    : "REVOKE ALL PRIVILEGES ON DATABASE \"{$name}\" FROM \"{$u}\";";
                $script .= self::sql($server, $stmt);

                if ($dbs->contains($name)) {
                    $script .= Bash::pipeSql("GRANT ALL ON SCHEMA public TO \"{$u}\";", "sudo -u postgres psql -v ON_ERROR_STOP=1 -d {$name}");
                }
            }

            return $script."echo 'User {$u} synced.'\n";
        }

        $host = $user->host ?: '%';
        $sql = "CREATE USER IF NOT EXISTS '{$u}'@'{$host}' IDENTIFIED BY '{$p}';\n"
            ."ALTER USER '{$u}'@'{$host}' IDENTIFIED BY '{$p}';\n"
            ."REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$u}'@'{$host}';\n";

        foreach ($dbs as $name) {
            $sql .= "GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$u}'@'{$host}';\n";
        }

        return self::sql($server, $sql."FLUSH PRIVILEGES;\n")."echo 'User {$u} synced.'\n";
    }

    public static function dropDatabaseUser(DatabaseUser $user): string
    {
        $server = $user->server;

        if ($server->database_type === 'postgresql') {
            $script = '';
            foreach ($server->databases()->pluck('name') as $name) {
                $script .= Bash::pipeSql("REASSIGN OWNED BY \"{$user->username}\" TO postgres; DROP OWNED BY \"{$user->username}\";", "sudo -u postgres psql -d {$name}")
                    ." || true\n";
            }

            return $script.self::sql($server, "DROP ROLE IF EXISTS \"{$user->username}\";");
        }

        $host = $user->host ?: '%';

        return self::sql($server, "DROP USER IF EXISTS '{$user->username}'@'{$host}';\nFLUSH PRIVILEGES;");
    }

    // ---------------------------------------------------------------- Cron

    public static function writeCronjob(Cronjob $job): string
    {
        $line = "# Managed by Deploi\n{$job->frequency} {$job->user} {$job->command} > /dev/null 2>&1\n";

        return Bash::writeFile('/etc/cron.d/'.$job->fileName(), $line)
            ."echo 'Cron job installed.'\n";
    }

    public static function removeCronjob(Cronjob $job): string
    {
        return 'rm -f '.Bash::q('/etc/cron.d/'.$job->fileName())."\necho 'Cron job removed.'\n";
    }

    // ---------------------------------------------------------------- Supervisor (daemons + queue workers)

    protected static function supervisorProgram(string $name, string $command, string $directory, string $user, int $processes, int $stopWait, string $logDir): string
    {
        $conf = <<<CONF
; Managed by Deploi
[program:{$name}]
command={$command}
directory={$directory}
user={$user}
numprocs={$processes}
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
startsecs=1
stopwaitsecs={$stopWait}
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile={$logDir}/{$name}.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
CONF;

        // supervisord writes program logs itself (as root), so a root-owned dir works for every user.
        return 'mkdir -p '.Bash::q($logDir)."\n"
            .Bash::writeFile("/etc/supervisor/conf.d/{$name}.conf", $conf)
            ."supervisorctl reread\nsupervisorctl update\nsupervisorctl restart '{$name}:*' || supervisorctl start '{$name}:*'\n"
            ."sleep 2\nsupervisorctl status '{$name}:*'\n";
    }

    protected static function removeSupervisorProgram(string $name): string
    {
        return "supervisorctl stop '{$name}:*' || true\nrm -f /etc/supervisor/conf.d/{$name}.conf\nsupervisorctl reread\nsupervisorctl update\necho 'Removed {$name}.'\n";
    }

    public static function writeDaemon(Daemon $daemon): string
    {
        $server = $daemon->server;

        return self::supervisorProgram(
            $daemon->programName(), $daemon->command, $daemon->directory ?: $server->homePath($daemon->user),
            $daemon->user, $daemon->processes, $daemon->stop_wait_seconds, self::LOG_DIR,
        );
    }

    public static function removeDaemon(Daemon $daemon): string
    {
        return self::removeSupervisorProgram($daemon->programName());
    }

    public static function restartSupervisorProgram(string $name): string
    {
        return "supervisorctl restart '{$name}:*'\nsupervisorctl status '{$name}:*'\n";
    }

    public static function writeQueueWorker(QueueWorker $worker): string
    {
        $site = $worker->site;

        return self::supervisorProgram(
            $worker->programName(), $worker->command(), $site->currentPath(), $site->system_user,
            $worker->processes, $worker->timeout + 10, self::LOG_DIR,
        );
    }

    public static function removeQueueWorker(QueueWorker $worker): string
    {
        return self::removeSupervisorProgram($worker->programName());
    }

    // ---------------------------------------------------------------- Firewall

    public static function addFirewallRule(FirewallRule $rule): string
    {
        return "ufw {$rule->ufwArguments()}\nufw reload || true\n";
    }

    public static function removeFirewallRule(FirewallRule $rule): string
    {
        return "ufw delete {$rule->ufwArguments()} || true\nufw reload || true\n";
    }

    // ---------------------------------------------------------------- SSH keys

    public static function addSshKey(ServerSshKey $key): string
    {
        $file = Bash::q("/home/{$key->user}/.ssh/authorized_keys");
        $line = Bash::q($key->authorizedKeyLine());

        return "mkdir -p /home/{$key->user}/.ssh\n"
            ."sed -i '/ {$key->marker()}\$/d' {$file} 2>/dev/null || true\n"
            ."echo {$line} >> {$file}\n"
            ."chown -R {$key->user}:{$key->user} /home/{$key->user}/.ssh\nchmod 600 {$file}\n"
            ."echo 'Key added for {$key->user}.'\n";
    }

    public static function removeSshKey(ServerSshKey $key): string
    {
        $file = Bash::q("/home/{$key->user}/.ssh/authorized_keys");

        return "sed -i '/ {$key->marker()}\$/d' {$file}\necho 'Key removed.'\n";
    }

    // ---------------------------------------------------------------- Services

    public const SERVICES = [
        'nginx' => 'Nginx',
        'apache2' => 'Apache',
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'postgresql' => 'PostgreSQL',
        'redis-server' => 'Redis',
        'supervisor' => 'Supervisor',
        'cron' => 'Cron',
        'fail2ban' => 'Fail2ban',
    ];

    public static function serviceAction(string $service, string $action): string
    {
        return "systemctl {$action} {$service}\nsystemctl is-active {$service}\n";
    }

    // ---------------------------------------------------------------- SSL

    public static function issueLetsEncrypt(Certificate $cert, string $email): string
    {
        $site = $cert->site;
        $domains = collect($cert->domains)->map(fn ($d) => '-d '.Bash::q($d))->implode(' ');
        $webroot = $site->server->homePath($site->system_user).'/.letsencrypt';

        return "mkdir -p {$webroot}\nchown {$site->system_user}: {$webroot}\n"
            ."certbot certonly --webroot -w {$webroot} --cert-name ".Bash::q($cert->name())." {$domains} \\\n"
            .'    --non-interactive --agree-tos -m '.Bash::q($email)." --keep-until-expiring --deploy-hook 'systemctl reload nginx'\n"
            .SiteScripts::nginxConfigFor($site, $cert)
            ."echo \"##DEPLOI_CERT_EXPIRY:\$(openssl x509 -enddate -noout -in {$cert->path()}/fullchain.pem | cut -d= -f2)##\"\n";
    }

    /**
     * For imported sites: let certbot's Nginx/Apache installer add the certificate to the site's
     * existing config in place (it backs the config up and validates it before reloading).
     */
    public static function issueLetsEncryptInPlace(Certificate $cert, string $email): string
    {
        $site = $cert->site;
        $plugin = $site->webserver() === 'apache' ? 'apache' : 'nginx';
        $domains = collect($cert->domains)->map(fn ($d) => '-d '.Bash::q($d))->implode(' ');

        return "APT=\"apt-get -y -o DPkg::Lock::Timeout=600\"\n"
            ."command -v certbot >/dev/null 2>&1 || { \$APT update && \$APT install certbot; }\n"
            ."dpkg -s python3-certbot-{$plugin} >/dev/null 2>&1 || \$APT install python3-certbot-{$plugin}\n"
            ."certbot --{$plugin} --cert-name ".Bash::q($cert->name())." {$domains} \\\n"
            .'    --non-interactive --agree-tos -m '.Bash::q($email)." --keep-until-expiring --redirect\n"
            ."echo \"##DEPLOI_CERT_EXPIRY:\$(openssl x509 -enddate -noout -in {$cert->path()}/fullchain.pem | cut -d= -f2)##\"\n";
    }

    public static function installCustomCertificate(Certificate $cert): string
    {
        return Bash::writeFile($cert->path().'/fullchain.pem', $cert->certificate, '644')
            .Bash::writeFile($cert->path().'/privkey.pem', $cert->private_key, '600')
            .SiteScripts::nginxConfigFor($cert->site, $cert)
            ."echo \"##DEPLOI_CERT_EXPIRY:\$(openssl x509 -enddate -noout -in {$cert->path()}/fullchain.pem | cut -d= -f2)##\"\n";
    }

    public static function removeCertificate(Certificate $cert, bool $wasActive): string
    {
        $script = $wasActive ? SiteScripts::nginxConfigFor($cert->site, null) : '';

        return $script.($cert->type === 'letsencrypt'
            ? 'certbot delete --cert-name '.Bash::q($cert->name())." --non-interactive || true\n"
            : 'rm -rf '.Bash::q($cert->path())."\n");
    }

    // ---------------------------------------------------------------- Backups (S3-compatible via rclone)

    public static function installBackup(DatabaseBackup $backup): string
    {
        $db = $backup->database;
        $server = $db->server;
        $c = $backup->disk->credentials;

        $dump = $server->database_type === 'postgresql'
            ? "sudo -u postgres pg_dump {$db->name}"
            : "mysqldump --single-transaction --quick --routines --triggers {$db->name}";

        $remote = 'deploi:'.trim($c['bucket'], '/').'/'.trim($backup->path, '/').'/'.$server->name.'/'.$db->name;
        $keepFrom = $backup->keep + 1;

        $env = collect([
            'RCLONE_CONFIG_DEPLOI_TYPE' => 's3',
            'RCLONE_CONFIG_DEPLOI_PROVIDER' => 'Other',
            'RCLONE_CONFIG_DEPLOI_ACCESS_KEY_ID' => $c['key'],
            'RCLONE_CONFIG_DEPLOI_SECRET_ACCESS_KEY' => $c['secret'],
            'RCLONE_CONFIG_DEPLOI_REGION' => $c['region'] ?: 'us-east-1',
            'RCLONE_CONFIG_DEPLOI_ENDPOINT' => $c['endpoint'] ?: 'https://s3.amazonaws.com',
        ])->map(fn ($v, $k) => "export {$k}=".Bash::q($v))->implode("\n");

        $runner = <<<BASH
#!/usr/bin/env bash
# Managed by Deploi - backup #{$backup->id} of {$db->name}
set -eo pipefail
{$env}
FILE=/tmp/{$db->name}-\$(date +%Y%m%d-%H%M%S).sql.gz
{$dump} | gzip > "\$FILE"
rclone copy "\$FILE" '{$remote}/' --s3-no-check-bucket
rm -f "\$FILE"
rclone lsf '{$remote}/' | sort -r | tail -n +{$keepFrom} | while read -r OLD; do rclone deletefile '{$remote}/'"\$OLD"; done
echo "Backup uploaded to {$remote}"
BASH;

        return "command -v rclone >/dev/null 2>&1 || apt-get -y -o DPkg::Lock::Timeout=600 install rclone\n"
            .Bash::writeFile($backup->scriptPath(), $runner, '700')
            .Bash::writeFile("/etc/cron.d/deploi-backup-{$backup->id}", "# Managed by Deploi\n{$backup->frequency} root {$backup->scriptPath()} >> /var/log/deploi-backup-{$backup->id}.log 2>&1\n")
            ."echo 'Backup schedule installed.'\n";
    }

    public static function runBackup(DatabaseBackup $backup): string
    {
        return "bash {$backup->scriptPath()}\n";
    }

    public static function removeBackup(DatabaseBackup $backup): string
    {
        return "rm -f {$backup->scriptPath()} /etc/cron.d/deploi-backup-{$backup->id}\necho 'Backup schedule removed.'\n";
    }

    // ---------------------------------------------------------------- Misc

    public static function reboot(): string
    {
        return "echo 'Rebooting in 2 seconds...'\nnohup bash -c 'sleep 2; reboot' >/dev/null 2>&1 &\n";
    }

    public static function stats(): string
    {
        return <<<'BASH'
echo "load=$(cut -d' ' -f1-3 /proc/loadavg)"
echo "cpus=$(nproc)"
free -m | awk '/Mem:/{print "mem_total="$2; print "mem_used="$3}'
df -P / | awk 'NR==2{print "disk_total="$2; print "disk_used="$3}'
echo "uptime=$(uptime -p)"
echo "os=$(. /etc/os-release && echo $PRETTY_NAME)"
BASH;
    }
}
