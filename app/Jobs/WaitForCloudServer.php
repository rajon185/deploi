<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Step 2: poll the provider until the machine is running and has a public IP. */
class WaitForCloudServer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    protected const MAX_ATTEMPTS = 60; // ~15 minutes

    public function __construct(public Server $server, public int $attempt = 1)
    {
        $this->onQueue('provisioning');
    }

    public function handle(): void
    {
        $server = $this->server->fresh();

        if (! $server || $server->status !== 'creating') {
            return;
        }

        try {
            $info = $server->serverProvider->driver()->getServer($server->provider_server_id);
        } catch (Throwable $e) {
            Log::warning("Polling server {$server->id} failed: {$e->getMessage()}");
            $info = ['status' => 'creating', 'ip' => null, 'private_ip' => null];
        }

        if ($info['status'] === 'active' && $info['ip']) {
            $server->update([
                'ip_address' => $info['ip'],
                'private_ip_address' => $info['private_ip'],
                'provision_step' => 'Waiting for SSH',
            ]);

            StartProvisioning::dispatch($server)->delay(now()->addSeconds(15));

            return;
        }

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            $server->update(['status' => 'failed', 'provision_step' => 'Timed out waiting for the provider to boot the server']);

            return;
        }

        self::dispatch($server, $this->attempt + 1)->delay(now()->addSeconds(15));
    }
}
