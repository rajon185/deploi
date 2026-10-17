<?php

namespace App\Tasks\Callbacks;

use App\Models\ServerTask;

/** Marks the database active/failed and, if the task also installed its engine, records that engine on the server. */
class DatabaseCreated implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        (new UpdateSubjectStatus)->handle($task);

        $engine = $task->options['install_engine'] ?? null;

        if (! $engine || ! $task->successful()) {
            return;
        }

        $server = $task->server->fresh();

        $server->update([
            'database_engines' => array_values(array_unique([...$server->databaseEngines(), $engine])),
            'database_type' => $server->database_type ?? $engine,
        ]);
    }
}
