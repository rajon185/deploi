<?php

namespace App\Tasks\Callbacks;

use App\Models\Deployment;
use App\Models\ServerTask;
use App\Services\Notifier;

class DeploymentFinished implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        /** @var Deployment|null $deployment */
        $deployment = $task->subject;

        if (! $deployment) {
            return;
        }

        $output = $task->output ?? '';
        $data = [
            'status' => $task->successful() ? 'finished' : 'failed',
            'finished_at' => now(),
        ];

        if (preg_match('/##DEPLOI_COMMIT:([0-9a-f]{7,40})\|(.*?)\|(.*?)##/', $output, $m)) {
            $data += ['commit_hash' => $m[1], 'commit_author' => $m[2], 'commit_message' => $m[3]];
        }

        if (preg_match('/##DEPLOI_RELEASE:(\d+)##/', $output, $m)) {
            $data['release'] = $m[1];
        }

        $deployment->update($data);

        $site = $deployment->site;

        if ($task->successful()) {
            $site->update(['last_deployed_at' => now()]);
        }

        $event = $task->successful() ? 'deployment.finished' : 'deployment.failed';
        $title = $task->successful() ? "Deployed {$site->domain}" : "Deployment failed: {$site->domain}";
        $message = ($deployment->commit_hash ? substr($deployment->commit_hash, 0, 7).' ' : '').($deployment->commit_message ?? '');

        Notifier::send($site->server->user, $event, $title, trim($message) ?: 'Deployment #'.$deployment->id, route('sites.deployments.show', [$site, $deployment]));
    }
}
