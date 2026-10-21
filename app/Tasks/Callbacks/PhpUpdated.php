<?php

namespace App\Tasks\Callbacks;

use App\Models\ServerTask;

/** Records the exact patch release (e.g. 8.4.2) reported by a PHP install/update task in the server's meta. */
class PhpUpdated implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        if (! preg_match_all('/##DEPLOI_PHP_VERSION:([0-9.]+):([0-9][0-9A-Za-z.\-]*)##/', (string) $task->output, $matches, PREG_SET_ORDER)) {
            return;
        }

        $server = $task->server->fresh();
        $meta = $server->meta ?? [];

        foreach ($matches as [, $version, $patch]) {
            $meta['php_patch_versions'][$version] = $patch;
        }

        $server->update(['meta' => $meta]);
    }
}
