<?php

namespace Tests\Feature;

use App\Jobs\CreateCloudServer;
use App\Jobs\RunServerTask;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Scripts\NginxConfig;
use App\Scripts\ProvisionScript;
use App\Scripts\ServerScripts;
use App\Scripts\SiteScripts;
use App\Services\Providers\ProviderManager;
use App\Tasks\Callbacks\DatabaseCreated;
use App\Tasks\Callbacks\DeploymentFinished;
use App\Tasks\Callbacks\PhpUpdated;
use App\Tasks\Callbacks\ProvisionFinished;
use App\Tasks\Callbacks\RepositoryInstalled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        $this->user = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
    }

    protected function activeServer(array $overrides = []): Server
    {
        return $this->user->servers()->create(array_merge([
            ...Server::generateKeyPair(),
            'name' => 'web-1',
            'type' => 'server',
            'provider' => 'custom',
            'ip_address' => '203.0.113.10',
            'php_version' => '8.3',
            'installed_php_versions' => ['8.3'],
            'database_type' => 'mysql',
            'sudo_password' => 'sudo-secret',
            'database_password' => 'dbsecret123',
            'deploy_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDeployKey deploi@web-1',
            'status' => 'active',
        ], $overrides));
    }

    /** Every generated script must at least be valid bash. */
    protected function assertBashSyntax(string $script, string $label = 'script'): void
    {
        $file = tempnam(sys_get_temp_dir(), 'deploi');
        file_put_contents($file, "set -eo pipefail\n".$script);
        exec('bash -n '.escapeshellarg($file).' 2>&1', $out, $code);
        unlink($file);

        $this->assertSame(0, $code, "{$label} has a bash syntax error:\n".implode("\n", $out));
    }

    protected function latestTask(): ServerTask
    {
        return ServerTask::latest('id')->firstOrFail();
    }

    public function test_registration_is_open_only_until_the_owner_exists(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee('Create the owner account');

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'password'])->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee('Welcome to Deploi');
    }

    public function test_custom_server_creation_generates_keys_and_shows_instructions(): void
    {
        $this->actingAs($this->user)->post('/servers', [
            'provider' => 'custom',
            'name' => 'my-droplet',
            'type' => 'server',
            'ip_address' => '198.51.100.5',
            'ssh_port' => 22,
            'php_version' => '8.4',
            'database_type' => 'postgresql',
            'install_redis' => 1,
            'install_node' => 0,
        ])->assertRedirect();

        $server = Server::firstOrFail();
        $this->assertSame('pending', $server->status);
        $this->assertStringStartsWith('ssh-ed25519 ', $server->public_key);
        $this->assertStringContainsString('OPENSSH PRIVATE KEY', $server->private_key);
        $this->assertNotSame($server->private_key, $server->getRawOriginal('private_key'), 'private key must be encrypted at rest');

        $this->get("/servers/{$server->id}")->assertOk()
            ->assertSee('Authorise Deploi on your server')
            ->assertSee(trim($server->public_key));

        Queue::assertNothingPushed();
    }

    public function test_cloud_server_creation_dispatches_provider_job(): void
    {
        $provider = $this->user->serverProviders()->create([
            'provider' => 'digitalocean', 'label' => 'DO', 'credentials' => ['token' => 'x'],
        ]);

        $this->actingAs($this->user)->post('/servers', [
            'provider' => (string) $provider->id,
            'server_provider_id' => $provider->id,
            'name' => 'do-web',
            'type' => 'server',
            'region' => 'ams3',
            'size' => 's-1vcpu-1gb',
            'php_version' => '8.3',
            'database_type' => 'mysql',
        ])->assertRedirect();

        $this->assertSame('creating', Server::firstOrFail()->status);
        Queue::assertPushed(CreateCloudServer::class);
    }

    public function test_digitalocean_driver_creates_droplet_with_key(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/account/keys' => Http::response(['ssh_key' => ['id' => 42]], 201),
            'api.digitalocean.com/v2/droplets' => Http::response(['droplet' => ['id' => 1234]], 202),
            'api.digitalocean.com/v2/droplets/1234' => Http::response(['droplet' => [
                'status' => 'active',
                'networks' => ['v4' => [
                    ['ip_address' => '10.0.0.5', 'type' => 'private'],
                    ['ip_address' => '203.0.113.50', 'type' => 'public'],
                ]],
            ]]),
        ]);

        $driver = ProviderManager::make('digitalocean', ['token' => 'secret']);
        $result = $driver->createServer('Web 1', 'ams3', 's-1vcpu-1gb', 'ssh-ed25519 AAAA test');

        $this->assertSame(['id' => '1234', 'ssh_key_id' => '42'], $result);
        $this->assertSame(['status' => 'active', 'ip' => '203.0.113.50', 'private_ip' => '10.0.0.5'], $driver->getServer('1234'));

        Http::assertSent(fn ($r) => $r->url() === 'https://api.digitalocean.com/v2/droplets'
            && $r['image'] === 'ubuntu-24-04-x64' && $r['ssh_keys'] === [42] && $r->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_provision_script_is_valid_bash_for_every_server_type(): void
    {
        foreach (array_keys(Server::TYPES) as $type) {
            foreach ([null, 'mysql', 'mariadb', 'postgresql'] as $db) {
                $server = $this->activeServer(['type' => $type, 'database_type' => $db, 'name' => "s-{$type}-".($db ?? 'none')]);
                $script = (new ProvisionScript($server))->render();
                $this->assertBashSyntax($script, "provision {$type}/{$db}");
                // Regression: heredocs drop their trailing newline, which once glued "rm -f x" onto "mkdir -p y".
                $this->assertDoesNotMatchRegularExpression('/\S(mkdir -p |echo \'[A-Za-z0-9+\/=]{16,}\' \| base64)/', $script);
                $this->assertStringContainsString('##DEPLOI_DEPLOY_KEY', $script);
            }
        }
    }

    public function test_provision_callback_activates_server_and_stores_deploy_key(): void
    {
        $server = $this->activeServer(['status' => 'provisioning', 'installed_php_versions' => null]);
        $task = $server->tasks()->create([
            'name' => 'Provision server', 'script' => 'x', 'status' => 'finished', 'exit_code' => 0,
            'output' => "lots of output\n##DEPLOI_DEPLOY_KEY:ssh-ed25519 AAAAnew deploi@web-1##\ndone",
        ]);

        (new ProvisionFinished)->handle($task);

        $server->refresh();
        $this->assertSame('active', $server->status);
        $this->assertSame('ssh-ed25519 AAAAnew deploi@web-1', $server->deploy_public_key);
        $this->assertSame(['8.3'], $server->installed_php_versions);
        $this->assertSame(3, $server->firewallRules()->count());
    }

    public function test_all_server_pages_render(): void
    {
        $server = $this->activeServer();
        $this->actingAs($this->user);

        foreach (['', '/databases', '/cronjobs', '/daemons', '/firewall', '/ssh-keys', '/services', '/tasks'] as $page) {
            $this->get("/servers/{$server->id}{$page}")->assertOk();
        }

        $this->get('/servers')->assertOk()->assertSee('web-1');
        $this->get('/servers/create')->assertOk();
        $this->get('/')->assertOk();
    }

    public function test_other_users_cannot_access_a_server_or_site(): void
    {
        $server = $this->activeServer();
        $site = $server->sites()->create(['domain' => 'example.com', 'status' => 'active']);
        $intruder = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'password']);

        $this->actingAs($intruder)->get("/servers/{$server->id}")->assertForbidden();
        $this->actingAs($intruder)->get("/sites/{$site->id}")->assertForbidden();
        $this->actingAs($intruder)->delete("/servers/{$server->id}", ['confirm' => 'web-1'])->assertForbidden();
    }

    public function test_server_resources_queue_valid_scripts(): void
    {
        $server = $this->activeServer();
        $this->actingAs($this->user);

        $this->post("/servers/{$server->id}/databases", ['name' => 'shop', 'username' => 'shop_user', 'password' => 'Str0ngPass!'])->assertRedirect();
        $this->assertBashSyntax($this->latestTask()->script, 'db user');
        preg_match("/echo '([A-Za-z0-9+\/=]+)' \| base64 -d \| mysql/", $this->latestTask()->script, $sql);
        $this->assertStringContainsString("CREATE USER IF NOT EXISTS 'shop_user'@'%' IDENTIFIED BY 'Str0ngPass!';", base64_decode($sql[1]));
        $this->assertStringContainsString('GRANT ALL PRIVILEGES ON `shop`.* TO', base64_decode($sql[1]));

        $this->post("/servers/{$server->id}/cronjobs", ['command' => 'php /home/deploi/x/artisan schedule:run', 'frequency' => '* * * * *', 'user' => 'deploi'])->assertRedirect();
        $this->assertBashSyntax($this->latestTask()->script, 'cron');

        $this->post("/servers/{$server->id}/daemons", ['command' => 'node server.js', 'directory' => '/home/deploi/app', 'user' => 'deploi', 'processes' => 2, 'stop_wait_seconds' => 10])->assertRedirect();
        $this->assertBashSyntax($this->latestTask()->script, 'daemon');

        $this->post("/servers/{$server->id}/firewall", ['name' => 'MySQL', 'port' => '3306', 'protocol' => 'tcp', 'from' => '1.2.3.4', 'rule_type' => 'allow'])->assertRedirect();
        $this->assertStringContainsString('ufw allow proto tcp from 1.2.3.4 to any port 3306', $this->latestTask()->script);

        $this->post("/servers/{$server->id}/ssh-keys", ['name' => 'Laptop', 'public_key' => 'ssh-ed25519 AAAAC3Nza laptop', 'user' => 'deploi'])->assertRedirect();
        $this->assertBashSyntax($this->latestTask()->script, 'ssh key');

        $this->post("/servers/{$server->id}/php", ['version' => '8.4'])->assertRedirect();
        $this->assertBashSyntax($this->latestTask()->script, 'php install');

        $this->post("/servers/{$server->id}/services/nginx/restart")->assertRedirect();

        // Injection attempts are rejected by validation
        $this->post("/servers/{$server->id}/databases", ['name' => 'x; DROP DATABASE y'])->assertSessionHasErrors('name');
        $this->post("/servers/{$server->id}/cronjobs", ['command' => "ls\n* * * * * root evil", 'frequency' => '* * * * *', 'user' => 'deploi'])->assertSessionHasErrors('command');

        // database + its user, cron, daemon, firewall, ssh key, php, service restart
        Queue::assertPushed(RunServerTask::class, 8);

        $this->get("/servers/{$server->id}/databases")->assertOk()->assertSee('shop');
    }

    public function test_creating_a_database_on_a_new_engine_installs_that_engine(): void
    {
        $server = $this->activeServer();
        $this->actingAs($this->user);

        $this->get("/servers/{$server->id}/databases")->assertOk()->assertSee('PostgreSQL (will be installed)');

        $this->post("/servers/{$server->id}/databases", ['engine' => 'postgresql', 'name' => 'analytics'])->assertSessionHasNoErrors();
        $task = $this->latestTask();
        $this->assertBashSyntax($task->script, 'install postgresql + create db');
        $this->assertStringContainsString('install postgresql postgresql-contrib', $task->script);
        $this->assertStringContainsString('sudo -u postgres createdb analytics', $task->script);
        $this->assertSame('postgresql', $server->databases()->firstWhere('name', 'analytics')->engine);

        $task->update(['status' => 'finished', 'exit_code' => 0]);
        (new DatabaseCreated)->handle($task->fresh());
        $server->refresh();
        $this->assertSame(['mysql', 'postgresql'], $server->databaseEngines());
        $this->assertSame('mysql', $server->database_type);
        $this->assertSame('active', $server->databases()->firstWhere('name', 'analytics')->status);

        // An installed engine is not reinstalled; the default engine stays the server's primary one.
        $this->post("/servers/{$server->id}/databases", ['engine' => 'postgresql', 'name' => 'reports'])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('apt-get', $this->latestTask()->script);
        $this->post("/servers/{$server->id}/databases", ['name' => 'shop'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('| mysql', $this->latestTask()->script);

        // MariaDB can't sit next to MySQL.
        $this->post("/servers/{$server->id}/databases", ['engine' => 'mariadb', 'name' => 'legacy'])->assertSessionHasErrors('engine');

        // Users belong to one engine and are only granted databases on it.
        $ids = $server->databases()->pluck('id', 'name');
        $this->post("/servers/{$server->id}/database-users", ['engine' => 'postgresql', 'username' => 'bi', 'databases' => [$ids['analytics'], $ids['shop']]])->assertSessionHasNoErrors();
        $user = $server->databaseUsers()->firstWhere('username', 'bi');
        $this->assertSame(['analytics'], $user->databases->pluck('name')->all());
        $this->assertStringContainsString('psql', $this->latestTask()->script);
        $this->assertBashSyntax($this->latestTask()->script, 'pg user');
    }

    public function test_database_server_without_an_engine_can_get_one(): void
    {
        $server = $this->activeServer(['type' => 'database', 'database_type' => null, 'database_password' => null]);
        $this->actingAs($this->user);

        $this->get("/servers/{$server->id}/databases")->assertOk()->assertSee('Create a database first');
        $this->post("/servers/{$server->id}/databases", ['engine' => 'mariadb', 'name' => 'app'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('install mariadb-server', $this->latestTask()->script);
        $this->assertNotEmpty($server->fresh()->database_password);

        $task = $this->latestTask();
        $task->update(['status' => 'finished', 'exit_code' => 0]);
        (new DatabaseCreated)->handle($task->fresh());
        $this->assertSame('mariadb', $server->fresh()->database_type);
    }

    public function test_installed_php_version_can_be_updated_to_the_latest_patch_release(): void
    {
        $server = $this->activeServer(['installed_php_versions' => ['8.3', '8.4']]);
        $this->actingAs($this->user);

        $this->get("/servers/{$server->id}/services")->assertOk()->assertSee(route('servers.php.update', [$server, '8.4']));

        $this->post("/servers/{$server->id}/php/8.4/update")->assertSessionHas('success');
        $task = $this->latestTask();
        $this->assertSame('Update PHP 8.4', $task->name);
        $this->assertBashSyntax($task->script, 'php update');
        $this->assertStringContainsString('install --only-upgrade $PACKAGES', $task->script);
        $this->assertStringContainsString("'php8.4-*'", $task->script);

        $task->update(['status' => 'finished', 'exit_code' => 0, 'output' => "Now running PHP 8.4.2\n##DEPLOI_PHP_VERSION:8.4:8.4.2##\n"]);
        (new PhpUpdated)->handle($task->fresh());
        $this->assertSame('8.4.2', $server->fresh()->meta['php_patch_versions']['8.4']);
        $this->get("/servers/{$server->id}/services")->assertSee('8.4.2');

        // Only installed versions can be updated.
        $this->post("/servers/{$server->id}/php/8.1/update")->assertNotFound();
    }

    public function test_site_lifecycle_create_install_deploy(): void
    {
        $server = $this->activeServer();
        $this->actingAs($this->user);

        $this->post("/servers/{$server->id}/sites", [
            'domain' => 'example.com', 'aliases' => 'www.example.com', 'project_type' => 'laravel',
            'web_directory' => '/public', 'php_version' => '8.3', 'zero_downtime' => 1,
        ])->assertRedirect();

        $site = Site::firstOrFail();
        $create = $this->latestTask();
        $this->assertBashSyntax($create->script, 'site create');
        $this->assertStringContainsString('sites-available/example.com', $create->script);

        $nginx = NginxConfig::render($site);
        $this->assertStringContainsString('server_name example.com www.example.com;', $nginx);
        $this->assertStringContainsString('root /home/deploi/example.com/current/public;', $nginx);
        $this->assertStringContainsString('unix:/run/php/php8.3-fpm.sock', $nginx);

        $site->update(['status' => 'active']);

        foreach (['', '/deploy-script', '/ssl', '/queue', '/settings'] as $page) {
            $this->get("/sites/{$site->id}{$page}")->assertOk();
        }

        // Install a repository from a custom git URL
        $this->post("/sites/{$site->id}/repository", [
            'repository_provider' => 'custom', 'repository' => 'git@git.example.com:team/app.git', 'branch' => 'main', 'auto_deploy' => 0,
        ])->assertRedirect();

        $install = $this->latestTask();
        $this->assertSame('deploi', $install->user);
        $this->assertBashSyntax($install->script, 'repository install');
        $this->assertSame('installing', $site->fresh()->repository_status);

        // Finishing the install triggers the first deployment
        $install->update(['status' => 'finished', 'exit_code' => 0]);
        (new RepositoryInstalled)->handle($install->fresh());

        $site->refresh();
        $this->assertSame('installed', $site->repository_status);
        $deployment = Deployment::firstOrFail();
        $this->assertSame('install', $deployment->triggered_by);
        $this->assertBashSyntax($deployment->task->script, 'zero-downtime deploy');
        $this->assertStringContainsString('mv -Tf', $deployment->task->script);

        // Finishing the deployment parses commit info
        $deployment->task->update([
            'status' => 'finished', 'exit_code' => 0,
            'output' => "##DEPLOI_RELEASE:20261012120000##\n...\n##DEPLOI_COMMIT:abcdef1234567|Jane|Fix the thing##\n",
        ]);
        (new DeploymentFinished)->handle($deployment->task->fresh());

        $deployment->refresh();
        $this->assertSame('finished', $deployment->status);
        $this->assertSame('abcdef1234567', $deployment->commit_hash);
        $this->assertSame('Fix the thing', $deployment->commit_message);
        $this->assertSame('20261012120000', $deployment->release);

        $this->get("/sites/{$site->id}")->assertOk()->assertSee('Fix the thing');
        $this->get("/sites/{$site->id}/deployments/{$deployment->id}")->assertOk();
        $this->get("/servers/{$server->id}/tasks/{$deployment->server_task_id}")->assertOk();
        $this->getJson("/servers/{$server->id}/tasks/{$deployment->server_task_id}/output")->assertJson(['status' => 'finished']);

        // Other actions
        $this->post("/sites/{$site->id}/queue", ['connection' => 'redis', 'queue' => 'default', 'processes' => 1, 'timeout' => 60, 'sleep' => 3, 'tries' => 1, 'memory' => 256])->assertRedirect();
        $this->assertBashSyntax($this->latestTask()->script, 'queue worker');

        $this->post("/sites/{$site->id}/ssl/letsencrypt", ['domains' => ['example.com', 'www.example.com']])->assertRedirect();
        $ssl = $this->latestTask()->script;
        $this->assertBashSyntax($ssl, 'letsencrypt');
        $this->assertStringContainsString('certbot certonly --webroot', $ssl);

        $this->post("/sites/{$site->id}/redirects", ['from' => '/old', 'to' => '/new', 'type' => 301])->assertRedirect();
        $this->assertStringContainsString("location = /old {\n        return 301 /new;", NginxConfig::render($site->fresh()));
        $this->assertBashSyntax($this->latestTask()->script, 'nginx update');

        $this->post("/sites/{$site->id}/deployments/{$deployment->id}/rollback")->assertRedirect();
        $this->assertBashSyntax($this->latestTask()->script, 'rollback');

        $this->delete("/sites/{$site->id}", ['confirm' => 'example.com'])->assertRedirect();
        $this->assertBashSyntax($this->latestTask()->script, 'site delete');
    }

    public function test_failed_key_authorisation_leaves_the_install_form_usable(): void
    {
        $server = $this->activeServer();
        $site = $server->sites()->create(['domain' => 'retry.test', 'status' => 'active']);
        $github = $this->user->sourceProviders()->create(['provider' => 'github', 'label' => 'Personal', 'token' => 'github_pat_x']);

        Http::fake(['api.github.com/user/keys' => Http::response(['message' => 'Resource not accessible by personal access token'], 403)]);

        $this->actingAs($this->user)->post("/sites/{$site->id}/repository", [
            'repository_provider' => 'github', 'source_provider_id' => $github->id,
            'repository' => 'acme/app', 'branch' => 'main', 'auto_deploy' => 0,
        ])->assertSessionHas('error', 'Could not add the server key to Personal: Resource not accessible by personal access token');

        $site->refresh();
        $this->assertNull($site->repository);
        $this->assertNull($site->repository_status);
        Queue::assertNothingPushed();

        $this->get("/sites/{$site->id}")->assertOk()->assertSee('Install a repository');

        // Sites left half-configured by the old behaviour still get the form back, prefilled.
        $site->update(['repository' => 'acme/app', 'repository_provider' => 'github', 'branch' => 'develop']);
        $this->get("/sites/{$site->id}")->assertOk()->assertSee('Install a repository')->assertSee('value="acme/app"', false)->assertSee('value="develop"', false)
            ->assertSee('value="github" x-model="provider" class="sr-only" checked', false);
    }

    public function test_web_directory_is_relative_to_the_site_folder(): void
    {
        $server = $this->activeServer();
        $site = $server->sites()->create(['domain' => 'webdir.test', 'status' => 'active', 'project_type' => 'laravel', 'web_directory' => '/public', 'php_version' => '8.3']);
        $this->actingAs($this->user);

        // A full path inside the site folder is converted to the relative part
        $this->put("/sites/{$site->id}", ['web_directory' => '/home/deploi/webdir.test/public', 'php_version' => '8.3'])->assertSessionHasNoErrors();
        $this->assertSame('/public', $site->fresh()->web_directory);
        $this->assertSame('/home/deploi/webdir.test/public', $site->fresh()->rootPath());

        // Any other absolute server path is refused (this is what broke a real site: "/home/ploi")
        $this->put("/sites/{$site->id}", ['web_directory' => '/home/ploi', 'php_version' => '8.3'])->assertSessionHasErrors('web_directory');
        $this->put("/sites/{$site->id}", ['web_directory' => '/public/../..', 'php_version' => '8.3'])->assertSessionHasErrors('web_directory');
        $this->assertSame('/public', $site->fresh()->web_directory);

        $this->put("/sites/{$site->id}", ['web_directory' => '', 'php_version' => '8.3'])->assertSessionHasNoErrors();
        $this->assertSame('/home/deploi/webdir.test', $site->fresh()->rootPath());
    }

    public function test_site_can_be_renamed_without_moving_anything(): void
    {
        $server = $this->activeServer();
        $site = $server->sites()->create([
            'domain' => 'writeandwrench.co.uk', 'aliases' => ['wrenchandwrite.co.uk', 'www.wrenchandwrite.co.uk'],
            'status' => 'active', 'project_type' => 'laravel', 'web_directory' => '/public', 'php_version' => '8.3',
            'ssl_status' => 'active',
        ]);
        $cert = $site->certificates()->create(['type' => 'letsencrypt', 'domains' => ['writeandwrench.co.uk'], 'status' => 'active', 'active' => true]);
        $oldCertPath = $cert->path();
        $webhook = $site->webhookUrl();
        $this->actingAs($this->user);

        $this->get("/sites/{$site->id}/settings")->assertOk()->assertSee('Site name &amp; visit link', false);

        $this->put("/sites/{$site->id}/domain", ['domain' => 'WrenchAndWrite.co.uk', 'keep_old_as_alias' => 1, 'visit_url' => ''])
            ->assertSessionHasNoErrors();

        $site->refresh();
        $this->assertSame('wrenchandwrite.co.uk', $site->domain);
        $this->assertSame(['writeandwrench.co.uk', 'www.wrenchandwrite.co.uk'], $site->aliases);
        $this->assertSame('/home/deploi/writeandwrench.co.uk', $site->path(), 'files stay where they are');
        $this->assertSame($oldCertPath, $cert->fresh()->path(), 'certificate path is pinned');
        $this->assertSame($webhook, $site->webhookUrl());

        $script = $server->tasks()->latest('id')->first()->script;
        $this->assertStringContainsString('sites-available/wrenchandwrite.co.uk', $script);
        $this->assertStringContainsString("rm -f /etc/nginx/sites-enabled/'writeandwrench.co.uk' /etc/nginx/sites-available/'writeandwrench.co.uk'", $script);
        $this->assertLessThan(strpos($script, 'rm -f /etc/nginx/sites-enabled/'), strpos($script, 'sites-available/wrenchandwrite.co.uk'), 'new file is written before the old one is removed');
        $nginx = NginxConfig::render($site, $site->activeCertificate());
        $this->assertStringContainsString('server_name wrenchandwrite.co.uk writeandwrench.co.uk www.wrenchandwrite.co.uk;', $nginx);
        $this->assertStringContainsString("ssl_certificate {$oldCertPath}/fullchain.pem;", $nginx);

        // Visit link
        $this->assertSame('https://wrenchandwrite.co.uk', $site->visitUrl());
        $this->put("/sites/{$site->id}/domain", ['domain' => 'wrenchandwrite.co.uk', 'visit_url' => 'https://www.wrenchandwrite.co.uk/admin'])->assertSessionHas('success', 'Visit link saved.');
        $this->assertSame('https://www.wrenchandwrite.co.uk/admin', $site->fresh()->visitUrl());
        $this->get("/sites/{$site->id}")->assertSee('href="https://www.wrenchandwrite.co.uk/admin"', false);
        $this->put("/sites/{$site->id}/domain", ['domain' => 'wrenchandwrite.co.uk', 'visit_url' => 'javascript:alert(1)'])->assertSessionHasErrors('visit_url');

        // Can't take a domain another site on the server already uses
        $server->sites()->create(['domain' => 'taken.test', 'status' => 'active']);
        $this->put("/sites/{$site->id}/domain", ['domain' => 'taken.test'])->assertSessionHasErrors('domain');
    }

    public function test_commands_run_in_the_site_directory_as_the_site_user(): void
    {
        $server = $this->activeServer();
        $site = $server->sites()->create([
            'domain' => 'cmd.test', 'status' => 'active', 'project_type' => 'laravel', 'php_version' => '8.4',
            'web_directory' => '/public', 'zero_downtime' => true,
        ]);
        $this->actingAs($this->user);

        $this->get("/sites/{$site->id}/commands")->assertOk()->assertSee('Run migrations')->assertSee('No commands run on this site yet.');

        $this->post("/sites/{$site->id}/commands", ['command' => "php artisan migrate --force\r\nphp artisan optimize", 'timeout' => 900])
            ->assertRedirect();

        $command = $site->commands()->firstOrFail();
        $task = $command->task;
        $this->assertSame("php artisan migrate --force\nphp artisan optimize", $command->command);
        $this->assertSame('deploi', $task->user);
        $this->assertSame(900, $task->timeout);
        $this->assertStringContainsString("cd '/home/deploi/cmd.test/current'", $task->script);
        $this->assertStringContainsString('php() { php8.4 "$@"; }', $task->script);
        $this->assertStringEndsWith("php artisan migrate --force\nphp artisan optimize", $task->script);
        $this->assertBashSyntax($task->script, 'site command');
        Queue::assertPushed(RunServerTask::class, fn ($job) => $job->task->is($task));

        $this->get("/sites/{$site->id}/commands")->assertOk()->assertSee('$ php artisan migrate --force', false)->assertSee('Run again');

        $this->post("/sites/{$site->id}/commands", ['command' => 'ls', 'timeout' => 99999])->assertSessionHasErrors('timeout');

        $intruder = User::create(['name' => 'X', 'email' => 'x2@example.com', 'password' => 'password']);
        $this->actingAs($intruder)->post("/sites/{$site->id}/commands", ['command' => 'id'])->assertForbidden();
        $this->assertSame(1, $site->commands()->count());
    }

    public function test_apply_env_command_is_valid_and_queued_as_a_site_command(): void
    {
        $site = $this->activeServer()->sites()->create(['domain' => 'env.test', 'status' => 'active', 'project_type' => 'laravel', 'php_version' => '8.4']);

        $script = SiteScripts::command($site, SiteScripts::APPLY_ENV_COMMAND);
        $this->assertBashSyntax($script, 'apply env');
        $this->assertStringContainsString('php artisan config:cache', $script);
        $this->assertStringContainsString('php artisan queue:restart', $script);
        $this->assertStringContainsString("export DEPLOI_PHP_FPM='php8.4-fpm'", $script);
        // Saving .env itself writes over a live SSH connection, so the controller isn't exercised here.
    }

    public function test_queue_worker_uses_its_queue_connection_not_the_database_connection(): void
    {
        $site = $this->activeServer()->sites()->create(['domain' => 'qw.test', 'status' => 'active', 'project_type' => 'laravel', 'php_version' => '8.4']);
        $worker = $site->queueWorkers()->create(['connection' => 'database', 'queue' => 'deployments,default', 'processes' => 3, 'timeout' => 3700]);

        // Regression: this used to render "queue:work sqlite/mysql" (Eloquent's DB connection name).
        $this->assertStringContainsString('queue:work database --queue=deployments,default', $worker->fresh()->command());

        // ...and the Supervisor config written to the server (it travels base64-encoded)
        preg_match("/echo '([^']+)' \\| base64 -d > '\\/etc\\/supervisor/", ServerScripts::writeQueueWorker($worker->fresh()), $m);
        $this->assertStringContainsString('queue:work database --queue=deployments,default', base64_decode($m[1]));
    }

    public function test_env_values_are_never_glued_onto_the_last_line(): void
    {
        $dir = sys_get_temp_dir().'/deploi-env-'.uniqid();
        mkdir($dir);
        $env = "{$dir}/.env";

        // A file without a trailing newline - exactly what the browser editor used to save.
        file_put_contents($env, "APP_NAME=Deploi\nREDIS_QUEUE_RETRY_AFTER=3800");
        $script = SiteScripts::setEnvValues($env, ['DB_QUEUE_RETRY_AFTER' => '3800', 'APP_NAME' => 'Renamed']);
        exec('bash -c '.escapeshellarg("set -eo pipefail\n".$script).' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertSame("APP_NAME=Renamed\nREDIS_QUEUE_RETRY_AFTER=3800\nDB_QUEUE_RETRY_AFTER=3800\n", file_get_contents($env));

        unlink($env);
        rmdir($dir);
    }

    public function test_git_host_is_inferred_when_no_tile_was_submitted(): void
    {
        $server = $this->activeServer();
        $site = $server->sites()->create(['domain' => 'nohost.test', 'status' => 'active']);
        $this->actingAs($this->user);

        // No repository_provider, no account, "owner/repo" → GitHub
        $this->post("/sites/{$site->id}/repository", ['repository' => 'acme/app', 'branch' => 'main'])->assertSessionHasNoErrors();
        $this->assertSame('github', $site->fresh()->repository_provider);

        // A full git URL → custom
        $other = $server->sites()->create(['domain' => 'nohost2.test', 'status' => 'active']);
        $this->post("/sites/{$other->id}/repository", ['repository' => 'git@git.example.com:team/app.git', 'branch' => 'main'])->assertSessionHasNoErrors();
        $this->assertSame('custom', $other->fresh()->repository_provider);
    }

    public function test_standard_deploy_script_is_valid_for_every_project_type(): void
    {
        $server = $this->activeServer();

        foreach (array_keys(Site::PROJECT_TYPES) as $type) {
            foreach ([false, true] as $zdt) {
                $site = $server->sites()->create([
                    'domain' => "{$type}-".(int) $zdt.'.test', 'project_type' => $type, 'zero_downtime' => $zdt,
                    'repository_provider' => 'github', 'repository' => 'acme/app', 'branch' => 'main',
                ]);
                $deployment = $site->deployments()->create();

                $this->assertBashSyntax(SiteScripts::deploy($site, $deployment), "deploy {$type} zdt=".(int) $zdt);
                $this->assertBashSyntax(SiteScripts::create($site), "create {$type}");
                $this->assertBashSyntax(SiteScripts::installRepository($site, null), "install {$type}");
            }
        }

        $this->assertBashSyntax(ServerScripts::stats(), 'stats');
        $this->assertBashSyntax(ServerScripts::reboot(), 'reboot');
    }

    public function test_webhook_deploys_only_the_configured_branch(): void
    {
        $server = $this->activeServer();
        $site = $server->sites()->create([
            'domain' => 'hook.test', 'status' => 'active', 'repository' => 'acme/app', 'branch' => 'main',
            'repository_provider' => 'github', 'repository_status' => 'installed',
        ]);

        $this->postJson("/deploy/{$site->deploy_webhook_token}", ['ref' => 'refs/heads/feature'])->assertOk()->assertJsonFragment(['message' => 'Ignored push to feature; site deploys main.']);
        $this->assertSame(0, $site->deployments()->count());

        $this->postJson("/deploy/{$site->deploy_webhook_token}", ['ref' => 'refs/heads/main'])->assertOk()->assertJsonFragment(['message' => 'Deployment queued.']);
        $this->postJson('/deploy/wrong-token')->assertNotFound();

        $this->assertSame(1, $site->deployments()->count());
    }

    public function test_api_lists_servers_and_triggers_deploys(): void
    {
        $server = $this->activeServer();
        $site = $server->sites()->create([
            'domain' => 'api.test', 'status' => 'active', 'repository' => 'acme/app', 'branch' => 'main',
            'repository_provider' => 'github', 'repository_status' => 'installed',
        ]);
        $token = $this->user->createToken('ci')->plainTextToken;

        $this->withToken($token)->getJson('/api/servers')->assertOk()->assertJsonPath('data.0.name', 'web-1')->assertJsonMissing(['private_key']);
        $this->withToken($token)->postJson("/api/sites/{$site->id}/deploy")->assertStatus(202);
        $this->withToken($token)->getJson("/api/sites/{$site->id}/deployments")->assertOk()->assertJsonCount(1, 'data');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/servers')->assertUnauthorized();
    }

    public function test_account_pages_render(): void
    {
        $this->actingAs($this->user);

        foreach (['providers', 'ssh-keys', 'notifications', 'backup-storage', 'api-tokens', 'profile'] as $page) {
            $this->get("/account/{$page}")->assertOk();
        }

        Http::fake(['api.github.com/user' => Http::response(['login' => 'octocat'])]);
        $this->post('/account/source-providers', ['provider' => 'github', 'label' => 'GH', 'token' => 'ghp_x'])->assertRedirect();
        $this->assertSame('octocat', $this->user->sourceProviders()->first()->username);

        Http::fake(['api.digitalocean.com/v2/account' => Http::response(['message' => 'Unable to authenticate you'], 401)]);
        $this->post('/account/server-providers', ['provider' => 'digitalocean', 'label' => 'DO', 'token' => 'bad'])
            ->assertSessionHas('error', 'The credentials were rejected: Unable to authenticate you');
    }
}
