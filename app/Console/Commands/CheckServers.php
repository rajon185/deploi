<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Notifier;
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
                $server->ssh()->connect(10)->exec('true', timeout: 10)->throw();

                $server->update(['status' => 'active', 'last_seen_at' => now()]);
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
