<?php

namespace App\Tasks\Callbacks;

use App\Models\Server;
use App\Models\ServerTask;
use App\Services\Notifier;

class ProvisionFinished implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        /** @var Server $server */
        $server = $task->server;

        if (! $task->successful()) {
            $server->update(['status' => 'failed', 'provision_step' => 'Provisioning failed - see the task log']);
            Notifier::send($server->user, 'server.failed', "Provisioning failed: {$server->name}", "Server {$server->ip_address} could not be provisioned.", route('servers.show', $server));

            return;
        }

        preg_match('/##DEPLOI_DEPLOY_KEY:(.+?)##/', $task->output ?? '', $m);

        $server->update([
            'status' => 'active',
            'provision_progress' => 100,
            'provision_step' => null,
            'provisioned_at' => now(),
            'deploy_public_key' => isset($m[1]) ? trim($m[1]) : null,
            'installed_php_versions' => $server->hasPhp() && $server->php_version ? [$server->php_version] : [],
        ]);

        foreach ([['SSH', '22'], ['HTTP', '80'], ['HTTPS', '443']] as [$name, $port]) {
            $server->firewallRules()->firstOrCreate(
                ['port' => $port, 'system' => true],
                ['name' => $name, 'protocol' => 'tcp', 'rule_type' => 'allow', 'status' => 'active'],
            );
        }

        Notifier::send($server->user, 'server.provisioned', "Server ready: {$server->name}", "{$server->name} ({$server->ip_address}) has been provisioned.", route('servers.show', $server));
    }
}
