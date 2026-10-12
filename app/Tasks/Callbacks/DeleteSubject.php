<?php

namespace App\Tasks\Callbacks;

use App\Models\ServerTask;

/** Used by "remove" scripts: delete the record once it's gone from the server. */
class DeleteSubject implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        $subject = $task->subject;

        if (! $subject) {
            return;
        }

        if ($task->successful()) {
            $subject->delete();
        } elseif (array_key_exists('status', $subject->getAttributes())) {
            $subject->update(['status' => 'failed']);
        }
    }
}
