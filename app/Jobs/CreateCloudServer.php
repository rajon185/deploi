<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Throwable;

/** Step 1 of a cloud server: ask the provider's API for a new machine with Deploi's key on it. */
class CreateCloudServer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Server $server)
    {
        $this->onQueue('provisioning');
    }

    public function handle(): void
    {
        $server = $this->server;
        $server->update(['status' => 'creating', 'provision_step' => 'Creating server at '.$server->serverProvider->label]);

        try {
            $result = $server->serverProvider->driver()->createServer($server->name, $server->region, $server->size, $server->public_key);
        } catch (Throwable $e) {
            $message = $e instanceof RequestException ? ($e->response->json('message') ?? $e->response->json('error.message') ?? $e->getMessage()) : $e->getMessage();
            $server->update(['status' => 'failed', 'provision_step' => 'Provider API error: '.$message]);

            return;
        }

        $server->update([
            'provider_server_id' => $result['id'],
            'provider_ssh_key_id' => $result['ssh_key_id'],
            'provision_step' => 'Waiting for the server to boot',
            'provision_progress' => 2,
        ]);

        WaitForCloudServer::dispatch($server)->delay(now()->addSeconds(20));
    }
}
