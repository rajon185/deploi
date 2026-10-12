<?php

namespace App\Http\Controllers;

use App\Jobs\CreateCloudServer;
use App\Jobs\StartProvisioning;
use App\Models\Server;
use App\Scripts\ServerScripts;
use App\Services\Providers\ProviderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ServerController extends Controller
{
    public function index()
    {
        return view('servers.index', [
            'servers' => auth()->user()->servers()->withCount('sites')->latest()->get(),
        ]);
    }

    public function create()
    {
        return view('servers.create', [
            'providers' => auth()->user()->serverProviders()->get(),
        ]);
    }

    public function store(Request $request)
    {
        if ($request->input('provider') === 'import') {
            return $this->storeImported($request);
        }

        $user = $request->user();
        $isCustom = $request->input('provider') === 'custom';

        $data = $request->validate([
            'provider' => ['required', 'string'],
            'name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9.-]*$/'],
            'type' => ['required', Rule::in(array_keys(Server::TYPES))],
            'server_provider_id' => [Rule::requiredIf(! $isCustom), 'nullable', Rule::exists('server_providers', 'id')->where('user_id', $user->id)],
            'region' => [Rule::requiredIf(! $isCustom), 'nullable', 'string'],
            'size' => [Rule::requiredIf(! $isCustom), 'nullable', 'string'],
            'ip_address' => [Rule::requiredIf($isCustom), 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:-]+$/'],
            'ssh_port' => ['nullable', 'integer', 'between:1,65535'],
            'php_version' => ['nullable', Rule::in(Server::PHP_VERSIONS)],
            'database_type' => ['nullable', Rule::in(array_keys(Server::DATABASES))],
            'install_redis' => ['boolean'],
            'install_node' => ['boolean'],
        ], ['name.regex' => 'Use letters, numbers, dots and dashes only.']);

        $provider = $isCustom ? null : $user->serverProviders()->findOrFail($data['server_provider_id']);

        $server = $user->servers()->create([
            ...Server::generateKeyPair('deploi-'.Str::slug($data['name'])),
            'name' => $data['name'],
            'type' => $data['type'],
            'provider' => $provider?->provider ?? 'custom',
            'server_provider_id' => $provider?->id,
            'region' => $data['region'] ?? null,
            'size' => $data['size'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'ssh_port' => $data['ssh_port'] ?? 22,
            'php_version' => in_array($data['type'], ['server', 'worker'], true) ? ($data['php_version'] ?? '8.3') : null,
            'database_type' => in_array($data['type'], ['server', 'database'], true) ? ($data['database_type'] ?? null) : null,
            'install_redis' => $request->boolean('install_redis'),
            'install_node' => $request->boolean('install_node'),
            'sudo_password' => Str::password(24, symbols: false),
            'database_password' => Str::password(24, symbols: false),
            'status' => $isCustom ? 'pending' : 'creating',
        ]);

        if (! $isCustom) {
            CreateCloudServer::dispatch($server);
        }

        return redirect()->route('servers.show', $server)->with('success', $isCustom
            ? 'Server added. Authorise Deploi\'s SSH key on it, then start provisioning.'
            : 'Server is being created at '.ProviderManager::name($server->provider).'. This usually takes 10-15 minutes.');
    }

    /**
     * Connect an existing, already-configured server. Nothing is installed: once Deploi's key is
     * authorised it scans the server and you choose which sites/databases to manage.
     */
    protected function storeImported(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'ip_address' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:-]+$/'],
            'ssh_port' => ['nullable', 'integer', 'between:1,65535'],
            'server_provider_id' => ['nullable', Rule::exists('server_providers', 'id')->where('user_id', $user->id)],
            'provider_server_id' => ['nullable', 'string', 'max:64'],
        ], ['name.regex' => 'Use letters, numbers, dots, dashes and underscores only.']);

        $provider = ! empty($data['server_provider_id']) ? $user->serverProviders()->find($data['server_provider_id']) : null;

        $server = $user->servers()->create([
            ...Server::generateKeyPair('deploi-'.Str::slug($data['name'])),
            'name' => $data['name'],
            'type' => 'server',
            'provider' => $provider?->provider ?? 'custom',
            'server_provider_id' => $provider?->id,
            'provider_server_id' => $provider ? ($data['provider_server_id'] ?? null) : null,
            'ip_address' => $data['ip_address'],
            'ssh_port' => $data['ssh_port'] ?? 22,
            'imported' => true,
            'webserver' => 'none',
            'install_redis' => false,
            'install_node' => false,
            'sudo_password' => Str::password(24, symbols: false), // unused on imported servers
            'status' => 'pending',
        ]);

        return redirect()->route('servers.show', $server)->with('success', 'Server added. Authorise Deploi\'s SSH key on it, then scan it.');
    }

    public function show(Server $server)
    {
        return view('servers.show', [
            'server' => $server->load('serverProvider'),
            'sites' => $server->sites()->latest()->get(),
            'tasks' => $server->tasks()->latest()->limit(5)->get(),
        ]);
    }

    /** Polled by the UI while a server is being created/provisioned. */
    public function status(Server $server)
    {
        return response()->json($server->only(['status', 'provision_step', 'provision_progress', 'ip_address']));
    }

    /** Custom servers: verify SSH works, then kick off provisioning. Also used to retry failures. */
    public function provision(Server $server)
    {
        abort_if(in_array($server->status, ['provisioning', 'active'], true), 422, 'Server is already provisioned.');
        abort_if($server->imported, 422, 'Imported servers are never provisioned - scan them instead.');

        if (! $server->isCustom() && ! $server->provider_server_id) {
            $server->update(['status' => 'creating', 'provision_step' => null]);
            CreateCloudServer::dispatch($server);

            return back()->with('success', 'Retrying server creation.');
        }

        try {
            $server->ssh()->connect(10)->exec('echo ok', timeout: 15)->throw();
        } catch (Throwable $e) {
            return back()->with('error', 'Deploi could not log in: '.$e->getMessage());
        }

        $server->update(['status' => 'connecting', 'provision_step' => 'Connected - starting provisioning', 'provision_progress' => 1]);
        StartProvisioning::dispatch($server);

        return back()->with('success', 'Connected! Provisioning has started.');
    }

    public function update(Request $request, Server $server)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'ip_address' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:-]+$/'],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
        ]);

        $server->update($data);

        return back()->with('success', 'Server updated.');
    }

    public function stats(Server $server)
    {
        try {
            $result = $server->ssh()->exec(ServerScripts::stats(), timeout: 20)->throw();
        } catch (Throwable $e) {
            $server->update(['status' => $server->isActive() ? 'disconnected' : $server->status]);

            return response()->json(['error' => $e->getMessage()], 503);
        }

        if ($server->status === 'disconnected') {
            $server->update(['status' => 'active']);
        }

        $stats = [];
        foreach (explode("\n", trim($result->output)) as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            $stats[$key] = $value;
        }

        return response()->json($stats);
    }

    public function reboot(Server $server)
    {
        try {
            if (! $server->isCustom() && $server->provider_server_id) {
                $server->serverProvider->driver()->rebootServer($server->provider_server_id);
            } else {
                $server->runTask('Reboot server', ServerScripts::reboot(), ['on_finish' => null, 'timeout' => 30]);
            }
        } catch (Throwable $e) {
            return back()->with('error', 'Reboot failed: '.$e->getMessage());
        }

        return back()->with('success', 'Server is rebooting.');
    }

    public function destroy(Request $request, Server $server)
    {
        $request->validate(['confirm' => ['required', Rule::in([$server->name])]], ['confirm.in' => 'Type the server name to confirm.']);

        if ($request->boolean('delete_at_provider') && $server->serverProvider && $server->provider_server_id) {
            try {
                $driver = $server->serverProvider->driver();
                $driver->deleteServer($server->provider_server_id);
                if ($server->provider_ssh_key_id) {
                    $driver->deleteSshKey($server->provider_ssh_key_id);
                }
            } catch (Throwable $e) {
                Log::warning("Deleting server {$server->id} at provider failed: {$e->getMessage()}");

                return back()->with('error', 'The provider refused to delete the server: '.$e->getMessage());
            }
        }

        $server->delete();

        return redirect()->route('servers.index')->with('success', "Server {$server->name} removed from Deploi.");
    }
}
