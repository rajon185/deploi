<?php

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Scripts\PhpScripts;
use App\Scripts\ServerScripts;
use App\Tasks\Callbacks\CliPhpChanged;
use App\Tasks\Callbacks\PhpInstalled;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function index(Server $server)
    {
        return view('servers.services', [
            'server' => $server,
            'services' => $this->servicesFor($server),
        ]);
    }

    public function action(Server $server, string $service, string $action)
    {
        abort_unless(array_key_exists($service, $this->servicesFor($server)), 404);
        abort_unless(in_array($action, ['restart', 'start', 'stop', 'reload'], true), 404);

        $server->runTask(ucfirst($action).' '.$service, ServerScripts::serviceAction($service, $action), ['on_finish' => null, 'timeout' => 120]);

        return back()->with('success', ucfirst($action)." {$service} queued.");
    }

    public function installPhp(Request $request, Server $server)
    {
        $data = $request->validate(['version' => ['required', Rule::in(Server::PHP_VERSIONS)]]);

        $server->runTask("Install PHP {$data['version']}", PhpScripts::installTask($server, $data['version']), [
            'on_finish' => PhpInstalled::class,
            'options' => ['version' => $data['version'], 'action' => 'install'],
            'timeout' => 1800,
        ]);

        return back()->with('success', "PHP {$data['version']} is being installed.");
    }

    public function uninstallPhp(Server $server, string $version)
    {
        abort_unless(in_array($version, $server->installed_php_versions ?? [], true), 404);
        abort_if($version === $server->php_version, 422, 'You cannot remove the default CLI PHP version.');
        abort_if($server->sites()->get()->contains(fn ($s) => $s->phpVersion() === $version), 422, 'Sites are still using this PHP version.');

        $server->runTask("Uninstall PHP {$version}", PhpScripts::uninstall($version), [
            'on_finish' => PhpInstalled::class,
            'options' => ['version' => $version, 'action' => 'uninstall'],
        ]);

        return back()->with('success', "PHP {$version} is being removed.");
    }

    public function setCliPhp(Request $request, Server $server)
    {
        $data = $request->validate(['version' => ['required', Rule::in($server->installed_php_versions ?? [])]]);

        $server->runTask("Set CLI PHP to {$data['version']}", PhpScripts::setCliDefault($data['version']), [
            'on_finish' => CliPhpChanged::class,
            'options' => ['version' => $data['version']],
        ]);

        return back()->with('success', "Default PHP CLI is being switched to {$data['version']}.");
    }

    protected function servicesFor(Server $server): array
    {
        $services = [];

        if ($server->hasNginx()) {
            $services['nginx'] = 'Nginx';
        } elseif ($server->webserver === 'apache') {
            $services['apache2'] = 'Apache';
        }

        foreach ($server->installed_php_versions ?? [] as $version) {
            $services["php{$version}-fpm"] = "PHP {$version} FPM";
        }

        if ($server->supportsDatabases()) {
            foreach ($server->databaseEngines() as $engine) {
                $services[$engine] = Server::DATABASES[$engine] ?? $engine;
            }
        }

        if ($server->install_redis) {
            $services['redis-server'] = 'Redis';
        }

        return $services + ['supervisor' => 'Supervisor', 'cron' => 'Cron', 'fail2ban' => 'Fail2ban'];
    }
}
