<?php

namespace App\Jobs;

use App\Models\Server;
use App\Scripts\ProvisionScript;
use App\Tasks\Callbacks\ProvisionFinished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Step 3: wait until SSH accepts Deploi's key, then run the provisioning script. */
class StartProvisioning implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    protected const MAX_ATTEMPTS = 30; // ~5 minutes

    public function __construct(public Server $server, public int $attempt = 1)
    {
        $this->onQueue('provisioning');
    }

    public function handle(): void
    {
        $server = $this->server->fresh();

        if (! $server || in_array($server->status, ['provisioning', 'active'], true)) {
            return;
        }

        try {
            $server->ssh()->connect(10)->exec('echo deploi-ok', timeout: 15)->throw();
        } catch (Throwable $e) {
            if ($this->attempt >= self::MAX_ATTEMPTS) {
                $server->update(['status' => 'failed', 'provision_step' => 'Could not connect over SSH: '.$e->getMessage()]);

                return;
            }

            $server->update(['provision_step' => "Waiting for SSH (attempt {$this->attempt})"]);
            self::dispatch($server, $this->attempt + 1)->delay(now()->addSeconds(10));

            return;
        }

        $server->update(['status' => 'provisioning', 'provision_step' => 'Starting provisioning', 'provision_progress' => 3]);

        $server->runTask('Provision server', (new ProvisionScript($server))->render(), [
            'timeout' => 3600,
            'on_finish' => ProvisionFinished::class,
            'queue' => 'provisioning',
            'options' => ['track_progress' => true],
        ]);
    }
}
