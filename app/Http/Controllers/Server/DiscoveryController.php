<?php

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Discovery\ServerDiscovery;
use Illuminate\Http\Request;
use Throwable;

/** "What's already on this server?" - scan an existing server and import its sites/databases. */
class DiscoveryController extends Controller
{
    public function show(Server $server)
    {
        return view('servers.discover', [
            'server' => $server,
            'discovery' => $server->discovery(),
            'managedSites' => $server->sites()->get()->keyBy('domain'),
            'managedDatabases' => $server->databases()->pluck('name')->all(),
        ]);
    }

    public function scan(Server $server)
    {
        try {
            $discovery = (new ServerDiscovery($server))->scan();
        } catch (Throwable $e) {
            return back()->with('error', 'Scan failed: '.$e->getMessage());
        }

        $meta = $server->meta ?? [];
        $meta['discovery'] = $discovery;
        $server->meta = $meta;
        $server->last_seen_at = now();

        // An imported server's details come from what's actually installed on it.
        if ($server->imported) {
            $this->applyToServer($server, $discovery);
        } elseif ($server->status === 'disconnected') {
            $server->status = 'active';
        }

        $server->save();

        return redirect()->route('servers.discover', $server)->with('success', sprintf(
            'Scan complete: found %d %s and %d %s.',
            count($discovery['sites']), str('site')->plural(count($discovery['sites'])),
            count($discovery['databases']), str('database')->plural(count($discovery['databases'])),
        ));
    }

    public function importSites(Request $request, Server $server)
    {
        $discovered = $server->discovery()['sites'] ?? [];
        $data = $request->validate(['sites' => ['required', 'array', 'min:1'], 'sites.*' => ['string']]);

        $imported = 0;

        foreach ($data['sites'] as $domain) {
            $found = $discovered[$domain] ?? null;

            if (! $found || $server->sites()->where('domain', $domain)->exists()) {
                continue;
            }

            $repo = ServerDiscovery::repositoryFields($found['is_git'] ?? false ? $found['repository'] : null);

            $site = $server->sites()->make([
                'domain' => $domain,
                'aliases' => $found['aliases'],
                'project_type' => $found['project_type'] ?? 'static',
                'web_directory' => $found['web_directory'] ?? '/',
                'directory' => $found['directory'],
                'php_version' => $found['php_version'] ?? ($found['php'] ? $server->php_version : null),
                'app_port' => $found['app_port'],
                'system_user' => $found['owner'] && $found['owner'] !== 'UNKNOWN' ? $found['owner'] : $server->system_user,
                'imported' => true,
                'status' => 'active',
                'ssl_status' => $found['ssl'] ? 'active' : null,
                'branch' => $repo['repository'] ? $found['branch'] : null,
                'repository_status' => $repo['repository'] ? 'installed' : null,
                ...$repo,
                'meta' => [
                    'webserver' => $found['webserver'],
                    'config_path' => $found['config_path'],
                    'shared_config' => $found['shared_config'],
                    'root' => $found['root'],
                    'error_log' => $found['error_log'],
                    'ssl_certificate' => $found['ssl_certificate'],
                    'ssl_expires' => $found['ssl_expires'] ?? null,
                ],
            ]);
            $site->deploy_script = $site->defaultDeployScript();
            $site->save();
            $imported++;
        }

        return back()->with('success', "Imported {$imported} ".str('site')->plural($imported).'. Their existing web server config and files were left untouched.');
    }

    public function importDatabases(Request $request, Server $server)
    {
        $found = collect($server->discovery()['databases'] ?? []);
        $discovered = $found->pluck('name')->all();
        $data = $request->validate(['databases' => ['required', 'array', 'min:1'], 'databases.*' => ['string']]);

        $imported = 0;

        foreach (array_intersect($data['databases'], $discovered) as $name) {
            if (! $server->databases()->where('name', $name)->exists()) {
                // The scan reports MySQL and MariaDB both as "mysql"; the server's engine list tells them apart.
                $engine = $found->firstWhere('name', $name)['engine'] ?? $server->database_type;
                $engine = $engine === 'mysql' && $server->hasDatabaseEngine('mariadb') ? 'mariadb' : $engine;
                $server->databases()->create(['name' => $name, 'engine' => $engine, 'status' => 'active']);
                $imported++;
            }
        }

        return back()->with('success', "Imported {$imported} ".str('database')->plural($imported).'.');
    }

    protected function applyToServer(Server $server, array $d): void
    {
        $software = $d['software'];
        $fpm = $software['php_fpm'] ?? [];
        $cli = $software['php_cli'] ?? null;
        $users = collect($d['users'])->pluck('name');

        $server->fill([
            'os' => $d['os'] ?? $server->os,
            'php_version' => $cli ?: (collect($fpm)->sort()->last() ?: null),
            'installed_php_versions' => array_values(array_unique(array_filter([...$fpm, ...($fpm ? [] : [$cli])]))),
            'database_type' => $d['database_type'],
            'database_engines' => $d['database_engines'] ?? array_filter([$d['database_type']]),
            'webserver' => isset($software['nginx']) ? 'nginx' : (isset($software['apache']) ? 'apache' : 'none'),
            'install_redis' => isset($software['redis']),
            'install_node' => isset($software['node']),
        ]);

        // Only pick the deploy user on the first scan, so later scans never change it under you.
        if (in_array($server->status, ['pending', 'connecting'], true)) {
            $owners = collect($d['sites'])->pluck('owner')->filter(fn ($o) => $o && $o !== 'root' && $o !== 'UNKNOWN')->countBy()->sortDesc();

            $server->system_user = match (true) {
                $users->contains('deploi') => 'deploi',
                $owners->isNotEmpty() => $owners->keys()->first(),
                $users->reject(fn ($u) => $u === 'www-data')->isNotEmpty() => $users->reject(fn ($u) => $u === 'www-data')->first(),
                default => 'root',
            };
            $server->status = 'active';
            $server->provision_step = null;
        }

        $server->deploy_public_key = $d['keys'][$server->system_user] ?? $server->deploy_public_key;
    }
}
