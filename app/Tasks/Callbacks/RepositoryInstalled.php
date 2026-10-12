<?php

namespace App\Tasks\Callbacks;

use App\Actions\DeploySite;
use App\Models\ServerTask;
use App\Models\Site;

class RepositoryInstalled implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        /** @var Site|null $site */
        $site = $task->subject;

        if (! $site) {
            return;
        }

        if (! $task->successful()) {
            $site->update(['repository_status' => 'failed']);

            return;
        }

        $site->update(['repository_status' => 'installed']);

        if ($task->options['deploy'] ?? true) {
            DeploySite::run($site, 'install');
        }
    }
}
