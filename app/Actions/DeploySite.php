<?php

namespace App\Actions;

use App\Models\Deployment;
use App\Models\Site;
use App\Scripts\SiteScripts;
use App\Tasks\Callbacks\DeploymentFinished;

class DeploySite
{
    /** Queue a deployment. If one is already queued/running for the site, that one is returned instead. */
    public static function run(Site $site, string $trigger = 'manual'): Deployment
    {
        $running = $site->deployments()->whereIn('status', ['queued', 'running'])->latest()->first();

        if ($running) {
            return $running;
        }

        $deployment = $site->deployments()->create(['status' => 'queued', 'triggered_by' => $trigger]);

        $task = $site->server->runTask("Deploy {$site->domain}", SiteScripts::deploy($site, $deployment), [
            'user' => $site->system_user,
            'timeout' => 1800,
            'subject' => $deployment,
            'on_finish' => DeploymentFinished::class,
            'queue' => 'deployments',
        ]);

        $deployment->update(['server_task_id' => $task->id]);

        return $deployment;
    }
}
