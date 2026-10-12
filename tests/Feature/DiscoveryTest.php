<?php

namespace Tests\Feature;

use App\Jobs\RunServerTask;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Discovery\ServerDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected const NGINX_SHOP = <<<'NGINX'
# Typical certbot-managed config
server {
    server_name shop.example.com www.shop.example.com;
    root /var/www/shop/public;   # Laravel

    index index.php;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    error_log /var/log/nginx/shop.error.log warn;

    listen 443 ssl; # managed by Certbot
    ssl_certificate /etc/letsencrypt/live/shop.example.com/fullchain.pem; # managed by Certbot
    ssl_certificate_key /etc/letsencrypt/live/shop.example.com/privkey.pem; # managed by Certbot
}
server {
    if ($host = shop.example.com) {
        return 301 https://$host$request_uri;
    } # managed by Certbot
    listen 80;
    server_name shop.example.com www.shop.example.com;
    return 404; # managed by Certbot
}
NGINX;

    protected const NGINX_MISC = <<<'NGINX'
server {
    listen 80 default_server;
    server_name _;
    root /var/www/html;
}
server {
    listen 80;
    server_name api.example.com;
    location / {
        proxy_pass http://127.0.0.1:3000;
    }
}
server {
    listen 80;
    server_name static.example.com;
    root /srv/static-site/dist;
}
NGINX;

    protected const APACHE_BLOG = <<<'APACHE'
<VirtualHost *:80>
    ServerName blog.example.org
    ServerAlias www.blog.example.org
    DocumentRoot /var/www/blog
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.1-fpm.sock|fcgi://localhost"
    </FilesMatch>
    ErrorLog ${APACHE_LOG_DIR}/blog-error.log
</VirtualHost>
APACHE;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        $this->user = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
    }

    protected function discoveredSites(): array
    {
        $sites = ServerDiscovery::buildSites([
            ['type' => 'nginx', 'path' => '/etc/nginx/sites-available/shop', 'content' => self::NGINX_SHOP],
            ['type' => 'nginx', 'path' => '/etc/nginx/conf.d/misc.conf', 'content' => self::NGINX_MISC],
            ['type' => 'apache', 'path' => '/etc/apache2/sites-available/blog.conf', 'content' => self::APACHE_BLOG],
        ]);

        return ServerDiscovery::enrichSites($sites, [
            '/var/www/shop/public' => [
                'exists' => 'yes', 'owner' => 'forge', 'artisan' => '/var/www/shop', 'git' => '/var/www/shop',
                'remote' => 'git@github.com:acme/shop.git', 'branch' => 'production',
            ],
            '/var/www/blog' => ['exists' => 'yes', 'owner' => 'www-data', 'wordpress' => '/var/www/blog'],
            '/srv/static-site/dist' => [
                'exists' => 'yes', 'owner' => 'ubuntu', 'git' => '/srv/static-site',
                'remote' => 'https://deploy:s3cret@gitlab.com/acme/static-site.git', 'branch' => 'main',
            ],
        ], ['/etc/letsencrypt/live/shop.example.com/fullchain.pem' => 'Jan 10 12:00:00 2027 GMT']);
    }

    public function test_nginx_and_apache_configs_are_parsed_into_sites(): void
    {
        $sites = $this->discoveredSites();

        $this->assertSame(['api.example.com', 'blog.example.org', 'shop.example.com', 'static.example.com'], array_keys($sites));

        $shop = $sites['shop.example.com'];
        $this->assertSame(['www.shop.example.com'], $shop['aliases']);
        $this->assertSame('laravel', $shop['project_type']);
        $this->assertSame('/var/www/shop', $shop['directory']);
        $this->assertSame('/public', $shop['web_directory']);
        $this->assertSame('8.2', $shop['php_version']);
        $this->assertTrue($shop['ssl']);
        $this->assertSame('/etc/letsencrypt/live/shop.example.com/fullchain.pem', $shop['ssl_certificate']);
        $this->assertSame('Jan 10 12:00:00 2027 GMT', $shop['ssl_expires']);
        $this->assertSame('/var/log/nginx/shop.error.log', $shop['error_log']);
        $this->assertSame('git@github.com:acme/shop.git', $shop['repository']);
        $this->assertSame('production', $shop['branch']);
        $this->assertTrue($shop['is_git']);
        $this->assertSame('forge', $shop['owner']);
        $this->assertFalse($shop['shared_config']);

        $api = $sites['api.example.com'];
        $this->assertSame('nodejs', $api['project_type']);
        $this->assertSame(3000, $api['app_port']);
        $this->assertTrue($api['shared_config']);

        $static = $sites['static.example.com'];
        $this->assertSame('static', $static['project_type']);
        $this->assertSame('/srv/static-site', $static['directory'], 'git checkout above the web root becomes the project directory');
        $this->assertSame('/dist', $static['web_directory']);
        $this->assertSame('https://gitlab.com/acme/static-site.git', $static['repository'], 'credentials must be stripped');

        $blog = $sites['blog.example.org'];
        $this->assertSame('apache', $blog['webserver']);
        $this->assertSame('wordpress', $blog['project_type']);
        $this->assertSame('8.1', $blog['php_version']);
        $this->assertSame('/var/log/apache2/blog-error.log', $blog['error_log']);
        $this->assertSame(['www.blog.example.org'], $blog['aliases']);
    }

    public function test_scan_output_is_parsed(): void
    {
        $raw = implode("\n", [
            '##DEPLOI_SECTION:info',
            'hostname=web-01',
            'os=Ubuntu 22.04.4 LTS',
            'nginx=1.18.0 (Ubuntu)',
            'php_cli=8.2',
            'php_fpm=8.1 8.2 ',
            'mysql=mysqld  Ver 10.6.16-MariaDB-0ubuntu0.22.04.1 for debian-linux-gnu',
            'redis=6.0.16',
            'ufw=active',
            '##DEPLOI_SECTION:users',
            'forge:/home/forge:/bin/bash',
            '##DEPLOI_SECTION:keys',
            'forge=ssh-ed25519 AAAAforge forge@web-01',
            '##DEPLOI_SECTION:webconfig',
            '##DEPLOI_FILE:nginx:/etc/nginx/sites-available/shop',
            self::NGINX_SHOP,
            '##DEPLOI_ENDFILE',
            '##DEPLOI_SECTION:databases',
            'mysql:information_schema',
            'mysql:shop_production',
            '##DEPLOI_SECTION:cron',
            '##DEPLOI_FILE:crontab:forge',
            '# m h dom mon dow command',
            '* * * * * php /var/www/shop/artisan schedule:run',
            '##DEPLOI_ENDFILE',
            '##DEPLOI_SECTION:supervisor',
            '##DEPLOI_FILE:supervisor:/etc/supervisor/conf.d/horizon.conf',
            '[program:horizon]',
            'command=php /var/www/shop/artisan horizon',
            'user=forge',
            '##DEPLOI_ENDFILE',
            '##DEPLOI_SECTION:firewall',
            'Status: active',
            '22/tcp ALLOW Anywhere',
            '##DEPLOI_SECTION:end',
        ]);

        $parsed = ServerDiscovery::parseScanOutput($raw);

        $this->assertSame('web-01', $parsed['info']['hostname']);
        $this->assertSame('ssh-ed25519 AAAAforge forge@web-01', $parsed['keys']['forge']);
        $this->assertSame(['nginx', 'crontab', 'supervisor'], array_column($parsed['files'], 'type'));
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/php8.2-fpm.sock', $parsed['files'][0]['content']);
        $this->assertSame(['mysql:information_schema', 'mysql:shop_production'], $parsed['databases']);
        $this->assertStringContainsString('22/tcp ALLOW', $parsed['firewall']);
    }

    public function test_bash_scripts_are_valid(): void
    {
        foreach ([ServerDiscovery::scanScript(), ServerDiscovery::inspectScript(['/var/www/my site/public'], ['/etc/ssl/a b.pem'])] as $script) {
            $file = tempnam(sys_get_temp_dir(), 'deploi');
            file_put_contents($file, $script);
            exec('bash -n '.escapeshellarg($file).' 2>&1', $out, $code);
            unlink($file);
            $this->assertSame(0, $code, implode("\n", $out));
        }
    }

    public function test_repository_fields(): void
    {
        $this->assertSame(['repository_provider' => 'github', 'repository' => 'acme/shop'], ServerDiscovery::repositoryFields('git@github.com:acme/shop.git'));
        $this->assertSame(['repository_provider' => 'gitlab', 'repository' => 'group/sub/app'], ServerDiscovery::repositoryFields('https://gitlab.com/group/sub/app.git'));
        $this->assertSame(['repository_provider' => 'bitbucket', 'repository' => 'team/site'], ServerDiscovery::repositoryFields('git@bitbucket.org:team/site.git'));
        $this->assertSame(['repository_provider' => 'custom', 'repository' => 'git@git.internal:x/y.git'], ServerDiscovery::repositoryFields('git@git.internal:x/y.git'));
    }

    protected function importedServerWithDiscovery(): Server
    {
        $this->actingAs($this->user)->post('/servers', [
            'provider' => 'import', 'name' => 'legacy-web', 'ip_address' => '198.51.100.20', 'ssh_port' => 22,
        ])->assertRedirect();

        $server = Server::firstOrFail();
        $server->update([
            'status' => 'active', 'webserver' => 'nginx', 'php_version' => '8.2', 'installed_php_versions' => ['8.1', '8.2'],
            'database_type' => 'mariadb', 'system_user' => 'forge',
            'meta' => ['discovery' => [
                'scanned_at' => now()->toIso8601String(), 'hostname' => 'web-01', 'os' => 'Ubuntu 22.04',
                'software' => ['nginx' => '1.18.0', 'php_fpm' => ['8.1', '8.2'], 'supervisor' => true],
                'ufw' => 'active', 'database_type' => 'mariadb',
                'users' => [['name' => 'forge', 'home' => '/home/forge', 'shell' => '/bin/bash']],
                'keys' => [], 'sites' => $this->discoveredSites(),
                'databases' => [['engine' => 'mysql', 'name' => 'shop_production']],
                'cron' => [['source' => 'crontab (forge)', 'lines' => ['* * * * * php artisan schedule:run']]],
                'supervisor' => [['path' => '/etc/supervisor/conf.d/horizon.conf', 'programs' => ['horizon' => ['command' => 'php artisan horizon', 'user' => 'forge', 'numprocs' => 1]]]],
                'firewall' => "Status: active\n22/tcp ALLOW Anywhere",
            ]],
        ]);

        return $server;
    }

    public function test_import_server_is_never_provisioned(): void
    {
        $this->actingAs($this->user)->post('/servers', [
            'provider' => 'import', 'name' => 'legacy-web', 'ip_address' => '198.51.100.20',
        ])->assertRedirect();

        $server = Server::firstOrFail();
        $this->assertTrue($server->imported);
        $this->assertSame('pending', $server->status);

        $this->get("/servers/{$server->id}")->assertOk()->assertSee('connect and scan')->assertDontSee('start provisioning');
        $this->post("/servers/{$server->id}/provision")->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_discover_page_lists_everything_and_imports_sites_without_touching_the_server(): void
    {
        $server = $this->importedServerWithDiscovery();

        $this->get("/servers/{$server->id}/discover")->assertOk()
            ->assertSee('shop.example.com')->assertSee('blog.example.org')
            ->assertSee('shop_production')->assertSee('php artisan horizon')->assertSee('22/tcp ALLOW');

        $this->post("/servers/{$server->id}/discover/sites", ['sites' => ['shop.example.com', 'static.example.com', 'not-real.com']])
            ->assertSessionHas('success');

        $this->assertSame(2, $server->sites()->count());
        $shop = Site::where('domain', 'shop.example.com')->firstOrFail();
        $this->assertTrue($shop->imported);
        $this->assertSame('/var/www/shop', $shop->path());
        $this->assertSame('/var/www/shop/public', $shop->rootPath());
        $this->assertSame('forge', $shop->system_user);
        $this->assertSame('github', $shop->repository_provider);
        $this->assertSame('acme/shop', $shop->repository);
        $this->assertSame('production', $shop->branch);
        $this->assertSame('installed', $shop->repository_status);
        $this->assertSame('active', $shop->ssl_status);
        $this->assertStringContainsString('git pull origin $DEPLOI_BRANCH', $shop->deploy_script);

        $this->post("/servers/{$server->id}/discover/databases", ['databases' => ['shop_production']])->assertSessionHas('success');
        $this->assertSame(1, $server->databases()->count());

        // Importing itself never runs anything on the server
        Queue::assertNothingPushed();

        $this->get("/servers/{$server->id}/discover")->assertOk()->assertSee('Managed in Deploi');
        $this->get("/servers/{$server->id}")->assertOk()->assertSee('Imported');
        foreach (['', '/deploy-script', '/ssl', '/settings', '/queue'] as $page) {
            $this->get("/sites/{$shop->id}{$page}")->assertOk();
        }
        $this->get("/sites/{$shop->id}/settings")->assertSee('/etc/nginx/sites-available/shop')->assertDontSee('Save &amp; update Nginx', false);
    }

    public function test_imported_sites_are_protected_from_destructive_actions(): void
    {
        $server = $this->importedServerWithDiscovery();
        $this->post("/servers/{$server->id}/discover/sites", ['sites' => ['shop.example.com', 'static.example.com']]);
        $shop = Site::where('domain', 'shop.example.com')->firstOrFail();
        $static = Site::where('domain', 'static.example.com')->firstOrFail();

        // No vhost rewrites
        $this->put("/sites/{$shop->id}", ['directory' => '/var/www/shop', 'system_user' => 'forge', 'project_type' => 'laravel', 'php_version' => '8.2', 'branch' => 'main'])->assertSessionHas('success');
        $this->assertSame('main', $shop->fresh()->branch);
        $this->post("/sites/{$shop->id}/redirects", ['from' => '/a', 'to' => '/b', 'type' => 301])->assertStatus(422);
        $this->post("/sites/{$shop->id}/ssl/custom", ['certificate' => '-----BEGIN CERTIFICATE-----', 'private_key' => '-----BEGIN PRIVATE KEY-----'])->assertStatus(422);

        // Installing a repository would wipe the directory
        $this->post("/sites/{$static->id}/repository", ['repository_provider' => 'custom', 'repository' => 'git@x.com:a/b.git', 'branch' => 'main'])->assertStatus(422);

        // Disconnecting the repository / removing the site leaves the server alone
        $this->delete("/sites/{$shop->id}/repository")->assertSessionHas('success');
        Queue::assertNothingPushed();

        $this->delete("/sites/{$shop->id}", ['confirm' => 'shop.example.com'])->assertRedirect();
        $this->assertNull(Site::find($shop->id));
        Queue::assertNothingPushed();
    }

    public function test_imported_site_deploys_and_uses_certbot_in_place(): void
    {
        $server = $this->importedServerWithDiscovery();
        $this->post("/servers/{$server->id}/discover/sites", ['sites' => ['shop.example.com']]);
        $shop = Site::firstOrFail();

        $this->post("/sites/{$shop->id}/deploy")->assertRedirect();
        Queue::assertPushed(RunServerTask::class, fn ($job) => $job->task->user === 'forge'
            && str_contains($job->task->script, "export DEPLOI_SITE_PATH='/var/www/shop'"));

        $this->post("/sites/{$shop->id}/ssl/letsencrypt", ['domains' => ['shop.example.com']])->assertRedirect();
        $script = $server->tasks()->latest('id')->first()->script;
        $this->assertStringContainsString('certbot --nginx', $script);
        $this->assertStringNotContainsString('sites-available', $script, 'must not overwrite the vhost');

        $this->get("/sites/{$shop->id}/ssl")->assertOk()->assertSee('/etc/letsencrypt/live/shop.example.com/fullchain.pem');
    }

    public function test_existing_droplets_can_be_listed(): void
    {
        $provider = $this->user->serverProviders()->create(['provider' => 'digitalocean', 'label' => 'DO', 'credentials' => ['token' => 't']]);
        $this->user->servers()->create([...Server::generateKeyPair(), 'name' => 'old', 'sudo_password' => 'x', 'server_provider_id' => $provider->id, 'provider_server_id' => '111']);

        Http::fake(['api.digitalocean.com/v2/droplets*' => Http::response(['droplets' => [
            ['id' => 111, 'name' => 'old', 'status' => 'active', 'region' => ['slug' => 'ams3'], 'size_slug' => 's-1vcpu-1gb', 'networks' => ['v4' => [['ip_address' => '203.0.113.1', 'type' => 'public']]]],
            ['id' => 222, 'name' => 'wordpress-box', 'status' => 'active', 'region' => ['slug' => 'nyc1'], 'size_slug' => 's-2vcpu-2gb', 'networks' => ['v4' => [['ip_address' => '203.0.113.2', 'type' => 'public']]]],
        ]])]);

        $this->actingAs($this->user)->getJson("/account/server-providers/{$provider->id}/servers")->assertOk()
            ->assertJsonPath('0.in_deploi', true)
            ->assertJsonPath('1.name', 'wordpress-box')
            ->assertJsonPath('1.ip', '203.0.113.2')
            ->assertJsonPath('1.in_deploi', false);

        $this->get('/servers/create?import=1')->assertOk()->assertSee('Existing server (import)');
    }
}
