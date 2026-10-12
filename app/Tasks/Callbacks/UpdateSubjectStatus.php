<?php

namespace App\Tasks\Callbacks;

use App\Models\ServerTask;

/** Default callback: mark the task's subject (cronjob, daemon, site...) active or failed. */
class UpdateSubjectStatus implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        $subject = $task->subject;

        if (! $subject || ! array_key_exists('status', $subject->getAttributes())) {
            return;
        }

        $subject->update(['status' => $task->successful() ? 'active' : 'failed']);
    }
}
