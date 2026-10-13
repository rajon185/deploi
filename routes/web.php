<?php

use App\Http\Controllers\Account\ProviderController;
use App\Http\Controllers\Account\SettingsController;
use App\Http\Controllers\Account\TwoFactorController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Server\CronjobController;
use App\Http\Controllers\Server\DaemonController;
use App\Http\Controllers\Server\DatabaseController;
use App\Http\Controllers\Server\DiscoveryController;
use App\Http\Controllers\Server\FirewallController;
use App\Http\Controllers\Server\ServiceController;
use App\Http\Controllers\Server\SshKeyController;
use App\Http\Controllers\Server\TaskController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\Site\CertificateController;
use App\Http\Controllers\Site\CommandController;
use App\Http\Controllers\Site\QueueWorkerController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Deploy webhook (GitHub / GitLab / Bitbucket / CI). CSRF-exempt, throttled.
Route::post('/deploy/{token}', WebhookController::class)->middleware('throttle:30,1')->name('webhook.deploy');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/two-factor-challenge', [AuthController::class, 'showTwoFactorChallenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    // ---------------------------------------------------------------- Servers
    Route::get('/servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('/servers/create', [ServerController::class, 'create'])->name('servers.create');
    Route::post('/servers', [ServerController::class, 'store'])->name('servers.store');

    Route::prefix('/servers/{server}')->middleware('can:manage,server')->scopeBindings()->name('servers.')->group(function () {
        Route::get('/', [ServerController::class, 'show'])->name('show');
        Route::put('/', [ServerController::class, 'update'])->name('update');
        Route::delete('/', [ServerController::class, 'destroy'])->name('destroy');
        Route::get('/status', [ServerController::class, 'status'])->name('status');
        Route::get('/stats', [ServerController::class, 'stats'])->name('stats');
        Route::post('/provision', [ServerController::class, 'provision'])->name('provision');
        Route::post('/reboot', [ServerController::class, 'reboot'])->name('reboot');

        Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');

        Route::get('/databases', [DatabaseController::class, 'index'])->name('databases');
        Route::post('/databases', [DatabaseController::class, 'store'])->name('databases.store');
        Route::delete('/databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
        Route::post('/database-users', [DatabaseController::class, 'storeUser'])->name('database-users.store');
        Route::put('/database-users/{databaseUser}', [DatabaseController::class, 'updateUser'])->name('database-users.update');
        Route::delete('/database-users/{databaseUser}', [DatabaseController::class, 'destroyUser'])->name('database-users.destroy');
        Route::get('/database-users/{databaseUser}/password', [DatabaseController::class, 'revealPassword'])->name('database-users.password');
        Route::post('/databases/{database}/backups', [DatabaseController::class, 'storeBackup'])->name('backups.store');
        Route::post('/databases/{database}/backups/{backup}/run', [DatabaseController::class, 'runBackup'])->name('backups.run');
        Route::delete('/databases/{database}/backups/{backup}', [DatabaseController::class, 'destroyBackup'])->name('backups.destroy');

        Route::get('/cronjobs', [CronjobController::class, 'index'])->name('cronjobs');
        Route::post('/cronjobs', [CronjobController::class, 'store'])->name('cronjobs.store');
        Route::delete('/cronjobs/{cronjob}', [CronjobController::class, 'destroy'])->name('cronjobs.destroy');

        Route::get('/daemons', [DaemonController::class, 'index'])->name('daemons');
        Route::post('/daemons', [DaemonController::class, 'store'])->name('daemons.store');
        Route::post('/daemons/{daemon}/restart', [DaemonController::class, 'restart'])->name('daemons.restart');
        Route::delete('/daemons/{daemon}', [DaemonController::class, 'destroy'])->name('daemons.destroy');

        Route::get('/firewall', [FirewallController::class, 'index'])->name('firewall');
        Route::post('/firewall', [FirewallController::class, 'store'])->name('firewall.store');
        Route::delete('/firewall/{firewallRule}', [FirewallController::class, 'destroy'])->name('firewall.destroy');

        Route::get('/ssh-keys', [SshKeyController::class, 'index'])->name('ssh-keys');
        Route::post('/ssh-keys', [SshKeyController::class, 'store'])->name('ssh-keys.store');
        Route::delete('/ssh-keys/{sshKey}', [SshKeyController::class, 'destroy'])->name('ssh-keys.destroy');

        Route::get('/services', [ServiceController::class, 'index'])->name('services');
        Route::post('/services/{service}/{action}', [ServiceController::class, 'action'])->name('services.action');
        Route::post('/php', [ServiceController::class, 'installPhp'])->name('php.install');
        Route::post('/php/cli', [ServiceController::class, 'setCliPhp'])->name('php.cli');
        Route::delete('/php/{version}', [ServiceController::class, 'uninstallPhp'])->name('php.uninstall');

        Route::get('/discover', [DiscoveryController::class, 'show'])->name('discover');
        Route::post('/discover', [DiscoveryController::class, 'scan'])->name('discover.scan');
        Route::post('/discover/sites', [DiscoveryController::class, 'importSites'])->name('discover.sites');
        Route::post('/discover/databases', [DiscoveryController::class, 'importDatabases'])->name('discover.databases');

        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::get('/tasks/{task}/output', [TaskController::class, 'output'])->name('tasks.output');
    });

    // ---------------------------------------------------------------- Sites
    Route::prefix('/sites/{site}')->middleware('can:manage,site')->scopeBindings()->name('sites.')->group(function () {
        Route::get('/', [SiteController::class, 'show'])->name('show');
        Route::put('/', [SiteController::class, 'update'])->name('update');
        Route::delete('/', [SiteController::class, 'destroy'])->name('destroy');
        Route::post('/deploy', [SiteController::class, 'deploy'])->name('deploy');
        Route::get('/deployments/{deployment}', [SiteController::class, 'deployment'])->name('deployments.show');
        Route::post('/deployments/{deployment}/rollback', [SiteController::class, 'rollback'])->name('deployments.rollback');

        Route::post('/repository', [SiteController::class, 'installRepository'])->name('repository.install');
        Route::delete('/repository', [SiteController::class, 'removeRepository'])->name('repository.destroy');
        Route::post('/auto-deploy', [SiteController::class, 'toggleAutoDeploy'])->name('auto-deploy');

        Route::get('/deploy-script', [SiteController::class, 'editDeployScript'])->name('deploy-script');
        Route::put('/deploy-script', [SiteController::class, 'updateDeployScript'])->name('deploy-script.update');
        Route::post('/webhook/regenerate', [SiteController::class, 'regenerateWebhook'])->name('webhook.regenerate');

        Route::get('/environment', [SiteController::class, 'editEnvironment'])->name('environment');
        Route::put('/environment', [SiteController::class, 'updateEnvironment'])->name('environment.update');

        Route::get('/logs', [SiteController::class, 'logs'])->name('logs');
        Route::get('/commands', [CommandController::class, 'index'])->name('commands');
        Route::post('/commands', [CommandController::class, 'store'])->name('commands.store')->middleware('throttle:30,1');
        Route::get('/settings', [SiteController::class, 'settings'])->name('settings');
        Route::put('/domain', [SiteController::class, 'updateDomain'])->name('domain.update');
        Route::post('/scheduler', [SiteController::class, 'addLaravelScheduler'])->name('scheduler');
        Route::post('/redirects', [SiteController::class, 'storeRedirect'])->name('redirects.store');
        Route::delete('/redirects/{redirect}', [SiteController::class, 'destroyRedirect'])->name('redirects.destroy');

        Route::get('/ssl', [CertificateController::class, 'index'])->name('ssl');
        Route::post('/ssl/letsencrypt', [CertificateController::class, 'letsEncrypt'])->name('ssl.letsencrypt');
        Route::post('/ssl/custom', [CertificateController::class, 'custom'])->name('ssl.custom');
        Route::delete('/ssl/{certificate}', [CertificateController::class, 'destroy'])->name('ssl.destroy');

        Route::get('/queue', [QueueWorkerController::class, 'index'])->name('queue');
        Route::post('/queue', [QueueWorkerController::class, 'store'])->name('queue.store');
        Route::post('/queue/{queueWorker}/restart', [QueueWorkerController::class, 'restart'])->name('queue.restart');
        Route::delete('/queue/{queueWorker}', [QueueWorkerController::class, 'destroy'])->name('queue.destroy');
    });

    // ---------------------------------------------------------------- Account
    Route::prefix('/account')->name('account.')->group(function () {
        Route::get('/profile', [SettingsController::class, 'profile'])->name('profile');
        Route::put('/profile', [SettingsController::class, 'updateProfile'])->name('profile.update');

        Route::post('/two-factor', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
        Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm')->middleware('throttle:10,1');
        Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('two-factor.recovery-codes');
        Route::delete('/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');

        Route::get('/providers', [ProviderController::class, 'index'])->name('providers');
        Route::post('/server-providers', [ProviderController::class, 'storeServerProvider'])->name('server-providers.store');
        Route::delete('/server-providers/{provider}', [ProviderController::class, 'destroyServerProvider'])->name('server-providers.destroy');
        Route::get('/server-providers/{provider}/regions', [ProviderController::class, 'regions'])->name('server-providers.regions');
        Route::get('/server-providers/{provider}/sizes', [ProviderController::class, 'sizes'])->name('server-providers.sizes');
        Route::get('/server-providers/{provider}/servers', [ProviderController::class, 'servers'])->name('server-providers.servers');
        Route::post('/source-providers', [ProviderController::class, 'storeSourceProvider'])->name('source-providers.store');
        Route::delete('/source-providers/{provider}', [ProviderController::class, 'destroySourceProvider'])->name('source-providers.destroy');
        Route::get('/source-providers/{provider}/repositories', [ProviderController::class, 'repositories'])->name('source-providers.repositories');

        Route::get('/ssh-keys', [SettingsController::class, 'sshKeys'])->name('ssh-keys');
        Route::post('/ssh-keys', [SettingsController::class, 'storeSshKey'])->name('ssh-keys.store');
        Route::delete('/ssh-keys/{key}', [SettingsController::class, 'destroySshKey'])->name('ssh-keys.destroy');

        Route::get('/notifications', [SettingsController::class, 'notifications'])->name('notifications');
        Route::post('/notifications', [SettingsController::class, 'storeNotificationChannel'])->name('notifications.store');
        Route::delete('/notifications/{channel}', [SettingsController::class, 'destroyNotificationChannel'])->name('notifications.destroy');

        Route::get('/backup-storage', [SettingsController::class, 'backupDisks'])->name('backup-disks');
        Route::post('/backup-storage', [SettingsController::class, 'storeBackupDisk'])->name('backup-disks.store');
        Route::delete('/backup-storage/{disk}', [SettingsController::class, 'destroyBackupDisk'])->name('backup-disks.destroy');

        Route::get('/api-tokens', [SettingsController::class, 'apiTokens'])->name('api-tokens');
        Route::post('/api-tokens', [SettingsController::class, 'storeApiToken'])->name('api-tokens.store');
        Route::delete('/api-tokens/{token}', [SettingsController::class, 'destroyApiToken'])->name('api-tokens.destroy');
    });
});
