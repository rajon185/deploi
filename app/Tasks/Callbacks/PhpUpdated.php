<?php

namespace App\Tasks\Callbacks;

use App\Models\Server;
use App\Models\ServerTask;

/** Records the exact patch release (e.g. 8.4.2) reported by a PHP install/update task in the server's meta. */
class PhpUpdated implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        self::record($task->server->fresh(), (string) $task->output);
    }

    /** Store every ##DEPLOI_PHP_VERSION:8.4:8.4.2## marker found in the output (see PhpScripts::reportVersion). */
    public static function record(Server $server, string $output): void
    {
        if (! preg_match_all('/##DEPLOI_PHP_VERSION:(\d+\.\d+):(\d+\.\d+\.\d+[0-9A-Za-z.\-]*)##/', $output, $matches, PREG_SET_ORDER)) {
            return;
        }

        $meta = $server->meta ?? [];

        foreach ($matches as [, $version, $patch]) {
            $meta['php_patch_versions'][$version] = $patch;
        }

        $server->update(['meta' => $meta]);
    }
}
