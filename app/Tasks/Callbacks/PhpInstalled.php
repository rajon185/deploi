<?php

namespace App\Tasks\Callbacks;

use App\Models\ServerTask;

class PhpInstalled implements TaskCallback
{
    public function handle(ServerTask $task): void
    {
        $server = $task->server;
        $version = $task->options['version'] ?? null;

        if (! $version || ! $task->successful()) {
            return;
        }

        $versions = collect($server->installed_php_versions ?? []);

        $versions = ($task->options['action'] ?? 'install') === 'uninstall'
            ? $versions->reject(fn ($v) => $v === $version)
            : $versions->push($version)->unique();

        $server->update(['installed_php_versions' => $versions->sort()->values()->all()]);

        (new PhpUpdated)->handle($task);
    }
}
