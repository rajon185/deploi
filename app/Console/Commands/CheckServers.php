<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Scripts\PhpScripts;
use App\Services\Notifier;
use App\Tasks\Callbacks\PhpUpdated;
use Illuminate\Console\Command;
use Throwable;

class CheckServers extends Command
{
    protected $signature = 'deploi:check-servers';

    protected $description = 'Check SSH connectivity to every provisioned server';

    public function handle(): void
    {
        Server::whereIn('status', ['active', 'disconnected'])->with('user')->each(function (Server $server) {
            try {
                // The connectivity check doubles as a refresh of the exact PHP releases (8.4.1 -> 8.4.2 after apt upgrades).
                $result = $server->ssh()->connect(10)->exec(PhpScripts::detectVersions(), timeout: 10)->throw();

                $server->update(['status' => 'active', 'last_seen_at' => now()]);
                PhpUpdated::record($server, $result->output);
                $this->line("✔ {$server->name}");
            } catch (Throwable $e) {
                $this->warn("✘ {$server->name}: {$e->getMessage()}");

                if ($server->status === 'active') {
                    $server->update(['status' => 'disconnected']);
                    Notifier::send($server->user, 'server.offline', "Server unreachable: {$server->name}", "Deploi could not connect to {$server->ip_address}: {$e->getMessage()}", route('servers.show', $server));
                }
            }
        });
    }
}
