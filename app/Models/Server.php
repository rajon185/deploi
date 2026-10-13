<?php

namespace App\Models;

use App\Jobs\RunServerTask;
use App\Services\Ssh\SshConnection;
use App\Tasks\Callbacks\UpdateSubjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use phpseclib3\Crypt\EC;

class Server extends Model
{
    public const TYPES = [
        'server' => 'Web server (Nginx + PHP + database)',
        'database' => 'Dedicated database server',
        'worker' => 'Worker server (PHP, no Nginx sites)',
        'load_balancer' => 'Load balancer (Nginx only)',
    ];

    // Stable releases available from the ondrej/php PPA. Add '8.6' once it's out of release candidate.
    public const PHP_VERSIONS = ['8.5', '8.4', '8.3', '8.2', '8.1', '8.0', '7.4'];

    public const DATABASES = [
        'mysql' => 'MySQL 8',
        'mariadb' => 'MariaDB',
        'postgresql' => 'PostgreSQL',
    ];

    protected $fillable = [
        'name', 'type', 'provider', 'server_provider_id', 'provider_server_id', 'ip_address', 'private_ip_address',
        'ssh_port', 'ssh_user', 'system_user', 'region', 'size', 'os', 'php_version', 'installed_php_versions',
        'database_type', 'webserver', 'install_redis', 'install_node', 'status', 'provision_step',
        'provision_progress', 'private_key', 'public_key', 'sudo_password', 'database_password',
        'deploy_public_key', 'provider_ssh_key_id', 'imported', 'provisioned_at', 'last_seen_at', 'meta',
    ];

    protected $hidden = ['private_key', 'sudo_password', 'database_password'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'sudo_password' => 'encrypted',
            'database_password' => 'encrypted',
            'installed_php_versions' => 'array',
            'install_redis' => 'boolean',
            'install_node' => 'boolean',
            'imported' => 'boolean',
            'meta' => 'array',
            'provisioned_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Generate a fresh Ed25519 key pair Deploi will use for root access to this server. */
    public static function generateKeyPair(string $comment = 'deploi'): array
    {
        $key = EC::createKey('Ed25519');

        return [
            'private_key' => $key->toString('OpenSSH'),
            'public_key' => $key->getPublicKey()->toString('OpenSSH', ['comment' => $comment]),
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serverProvider(): BelongsTo
    {
        return $this->belongsTo(ServerProvider::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ServerTask::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    public function databaseUsers(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }

    public function cronjobs(): HasMany
    {
        return $this->hasMany(Cronjob::class);
    }

    public function daemons(): HasMany
    {
        return $this->hasMany(Daemon::class);
    }

    public function firewallRules(): HasMany
    {
        return $this->hasMany(FirewallRule::class);
    }

    public function sshKeys(): HasMany
    {
        return $this->hasMany(ServerSshKey::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCustom(): bool
    {
        return $this->provider === 'custom';
    }

    public function hasNginx(): bool
    {
        return in_array($this->type, ['server', 'load_balancer'], true) && $this->webserver === 'nginx';
    }

    /** Result of the last "discover" scan (software, sites, databases, cron...), see ServerDiscovery. */
    public function discovery(): ?array
    {
        return $this->meta['discovery'] ?? null;
    }

    public function hasPhp(): bool
    {
        return in_array($this->type, ['server', 'worker'], true);
    }

    public function hasDatabase(): bool
    {
        return $this->database_type !== null && in_array($this->type, ['server', 'database'], true);
    }

    public function homePath(?string $user = null): string
    {
        $user ??= $this->system_user;

        return $user === 'root' ? '/root' : '/home/'.$user;
    }

    public function ssh(?string $user = null): SshConnection
    {
        return new SshConnection($this, $user ?? $this->ssh_user);
    }

    /**
     * Queue a script for execution on this server. Every action Deploi takes on a server goes
     * through here so it shows up in the server's task log.
     *
     * @param  array{user?: string, timeout?: int, subject?: Model, on_finish?: class-string|null, queue?: string, options?: array}  $opts
     */
    public function runTask(string $name, string $script, array $opts = []): ServerTask
    {
        $subject = $opts['subject'] ?? null;

        $task = $this->tasks()->create([
            'name' => $name,
            'user' => $opts['user'] ?? 'root',
            'script' => $script,
            'timeout' => $opts['timeout'] ?? 600,
            'on_finish' => array_key_exists('on_finish', $opts) ? $opts['on_finish'] : UpdateSubjectStatus::class,
            'options' => $opts['options'] ?? null,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);

        RunServerTask::dispatch($task)->onQueue($opts['queue'] ?? 'default');

        return $task;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'active' => 'green',
            'failed', 'disconnected' => 'red',
            default => 'amber',
        };
    }
}
