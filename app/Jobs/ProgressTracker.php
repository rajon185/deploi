<?php

namespace App\Jobs;

use App\Models\Server;

/**
 * Watches provisioning output for "##DEPLOI_PROGRESS:<percent>:<step>##" markers echoed by the
 * provision script and mirrors them onto the server so the UI can show a progress bar.
 */
class ProgressTracker
{
    protected string $carry = '';

    public function __construct(protected Server $server, protected bool $enabled) {}

    public function scan(string $chunk): void
    {
        if (! $this->enabled) {
            return;
        }

        $text = $this->carry.$chunk;

        if (preg_match_all('/##DEPLOI_PROGRESS:(\d+):([^#\n]+)##/', $text, $matches, PREG_SET_ORDER)) {
            $last = end($matches);
            $this->server->forceFill([
                'provision_progress' => min(100, (int) $last[1]),
                'provision_step' => trim($last[2]),
            ])->saveQuietly();
        }

        // Keep the tail in case a marker was split across chunks.
        $this->carry = substr($text, -200);
    }
}
