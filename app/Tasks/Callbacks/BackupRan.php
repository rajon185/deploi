<?php

namespace App\Tasks\Callbacks;

use App\Models\ServerTask;

class BackupRan implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        if ($task->successful() && $task->subject) {
            $task->subject->update(['last_run_at' => now()]);
        }
    }
}
