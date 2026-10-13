<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Site extends Model
{
    public const PROJECT_TYPES = [
        'laravel' => 'Laravel',
        'php' => 'Generic PHP',
        'wordpress' => 'WordPress',
        'static' => 'Static HTML / SPA',
        'nodejs' => 'Node.js (reverse proxy)',
    ];

    public const REPOSITORY_PROVIDERS = [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
        'custom' => 'Custom Git URL',
    ];

    protected $fillable = [
        'domain', 'aliases', 'project_type', 'web_directory', 'php_version', 'app_port', 'system_user',
        'source_provider_id', 'repository_provider', 'repository', 'branch', 'zero_downtime', 'keep_releases',
        'deploy_script', 'deploy_webhook_token', 'auto_deploy', 'provider_webhook_id', 'status',
        'repository_status', 'ssl_status', 'last_deployed_at', 'imported', 'directory', 'meta', 'visit_url',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'zero_downtime' => 'boolean',
            'auto_deploy' => 'boolean',
            'imported' => 'boolean',
            'meta' => 'array',
            'last_deployed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Site $site) {
            $site->deploy_webhook_token ??= Str::random(48);
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function sourceProvider(): BelongsTo
    {
        return $this->belongsTo(SourceProvider::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function activeCertificate(): ?Certificate
    {
        return $this->certificates()->where('active', true)->where('status', 'active')->latest()->first();
    }

    public function queueWorkers(): HasMany
    {
        return $this->hasMany(QueueWorker::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(SiteCommand::class);
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(Redirect::class);
    }

    /** All domains this site answers to. */
    public function allDomains(): array
    {
        return array_values(array_unique(array_filter([$this->domain, ...($this->aliases ?? [])])));
    }

    /**
     * Imported sites keep their existing Nginx/Apache config: Deploi must never rewrite or delete it,
     * nor the site's files. Settings that would regenerate the vhost are disabled for them.
     */
    public function managesWebserverConfig(): bool
    {
        return ! $this->imported;
    }

    public function webserver(): string
    {
        return $this->meta['webserver'] ?? 'nginx';
    }

    public function path(): string
    {
        // Imported sites live wherever they already were (e.g. /var/www/example.com).
        return $this->directory ?: "/home/{$this->system_user}/{$this->domain}";
    }

    /** The directory the application code is live in (the "current" symlink for zero-downtime sites). */
    public function currentPath(): string
    {
        return $this->zero_downtime ? $this->path().'/current' : $this->path();
    }

    public function rootPath(): string
    {
        $dir = trim($this->web_directory ?? '', '/');

        return rtrim($this->currentPath().'/'.$dir, '/');
    }

    public function phpVersion(): string
    {
        return $this->php_version ?: ($this->server->php_version ?: '8.3');
    }

    public function usesPhp(): bool
    {
        return in_array($this->project_type, ['laravel', 'php', 'wordpress'], true);
    }

    public function hasRepository(): bool
    {
        return filled($this->repository);
    }

    public function isLaravel(): bool
    {
        return $this->project_type === 'laravel';
    }

    public function gitUrl(): ?string
    {
        if (! $this->repository) {
            return null;
        }

        if ($this->repository_provider === 'custom' || str_contains($this->repository, ':')) {
            return $this->repository;
        }

        $host = match ($this->repository_provider) {
            'gitlab' => $this->sourceProvider?->url ? parse_url($this->sourceProvider->url, PHP_URL_HOST) : 'gitlab.com',
            'bitbucket' => 'bitbucket.org',
            default => 'github.com',
        };

        return "git@{$host}:{$this->repository}.git";
    }

    /** Where the "Visit" button goes: a custom link if set, else the primary domain. */
    public function visitUrl(): string
    {
        return $this->visit_url ?: ($this->ssl_status === 'active' ? 'https' : 'http').'://'.$this->domain;
    }

    public function webhookUrl(): string
    {
        return rtrim(config('deploi.webhook_url'), '/').'/deploy/'.$this->deploy_webhook_token;
    }

    /** Ships with every new site; the user can edit it freely. */
    public function defaultDeployScript(): string
    {
        $pull = match (true) {
            $this->zero_downtime => '',
            $this->imported && ! $this->hasRepository() => "# No git repository was detected - add your own update steps here.\n",
            default => "git pull origin \$DEPLOI_BRANCH\n",
        };

        return match ($this->project_type) {
            'laravel' => <<<BASH
cd \$DEPLOI_RELEASE_PATH
{$pull}\$DEPLOI_COMPOSER install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Build front-end assets if the project has them
if [ -f package-lock.json ]; then
    npm ci --no-audit --no-fund && npm run build
fi

\$DEPLOI_PHP artisan migrate --force
\$DEPLOI_PHP artisan optimize

# Reload PHP-FPM so OPcache picks up the new code
sudo -n /usr/sbin/service \$DEPLOI_PHP_FPM reload || echo "Could not reload \$DEPLOI_PHP_FPM (no passwordless sudo) - skipping"

# Gracefully restart queue workers
\$DEPLOI_PHP artisan queue:restart

echo "🚀 Application deployed!"
BASH,
            'php', 'wordpress' => <<<BASH
cd \$DEPLOI_RELEASE_PATH
{$pull}
if [ -f composer.json ]; then
    \$DEPLOI_COMPOSER install --no-interaction --prefer-dist --optimize-autoloader --no-dev
fi

sudo -n /usr/sbin/service \$DEPLOI_PHP_FPM reload || echo "Could not reload \$DEPLOI_PHP_FPM (no passwordless sudo) - skipping"

echo "🚀 Application deployed!"
BASH,
            'nodejs' => <<<BASH
cd \$DEPLOI_RELEASE_PATH
{$pull}npm ci --no-audit --no-fund
npm run build --if-present

# Restart the app's daemon (create one under Server → Daemons, e.g. "npm run start")
sudo -n /usr/bin/supervisorctl restart all

echo "🚀 Application deployed!"
BASH,
            default => <<<BASH
cd \$DEPLOI_RELEASE_PATH
{$pull}
if [ -f package-lock.json ]; then
    npm ci --no-audit --no-fund && npm run build --if-present
fi

echo "🚀 Site deployed!"
BASH,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'active' => 'green',
            'failed' => 'red',
            default => 'amber',
        };
    }
}
