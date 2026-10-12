<?php

namespace App\Tasks\Callbacks;

use App\Models\ServerTask;

class CliPhpChanged implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        if ($task->successful() && ($version = $task->options['version'] ?? null)) {
            $task->server->update(['php_version' => $version]);
        }
    }
}
