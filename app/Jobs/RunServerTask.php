<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Models\ServerTask;
use App\Tasks\Callbacks\TaskCallback;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executes a ServerTask's script over SSH, streams output into the database every second so the UI
 * can tail it live, then hands the finished task to its on_finish callback.
 */
class RunServerTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3700;

    public function __construct(public ServerTask $task) {}

    public function handle(): void
    {
        $task = $this->task->fresh();
        $server = $task->server;

        $task->update(['status' => 'running', 'started_at' => now(), 'output' => '']);

        if ($task->subject instanceof Deployment) {
            $task->subject->update(['status' => 'running', 'started_at' => now()]);
        }

        $buffer = '';
        $lastFlush = microtime(true);
        $tracker = new ProgressTracker($server, (bool) ($task->options['track_progress'] ?? false));

        try {
            $result = $server->ssh()->runScript($task->script, $task->user, function (string $chunk) use (&$buffer, &$lastFlush, $task, $tracker) {
                $buffer .= $chunk;
                $tracker->scan($chunk);

                if (microtime(true) - $lastFlush >= 1) {
                    $task->forceFill(['output' => $buffer])->saveQuietly();
                    $lastFlush = microtime(true);
                }
            }, $task->timeout);

            $status = match (true) {
                $result->timedOut => 'timeout',
                $result->exitCode === 0 => 'finished',
                default => 'failed',
            };

            if ($result->timedOut) {
                $buffer .= "\n[deploi] Task timed out after {$task->timeout} seconds.";
            }

            $task->update([
                'output' => $buffer,
                'exit_code' => $result->exitCode,
                'status' => $status,
                'finished_at' => now(),
            ]);

            $server->forceFill(['last_seen_at' => now()])->saveQuietly();
        } catch (Throwable $e) {
            $task->update([
                'output' => $buffer."\n[deploi] ".$e->getMessage(),
                'status' => 'failed',
                'finished_at' => now(),
            ]);
        }

        $this->runCallback($task);
    }

    public function failed(?Throwable $e): void
    {
        $task = $this->task->fresh();

        if ($task && $task->isRunning()) {
            $task->update([
                'output' => $task->output."\n[deploi] Worker failed: ".($e?->getMessage() ?? 'unknown error'),
                'status' => 'failed',
                'finished_at' => now(),
            ]);

            $this->runCallback($task);
        }
    }

    protected function runCallback(ServerTask $task): void
    {
        if ($task->on_finish && class_exists($task->on_finish)) {
            /** @var TaskCallback $callback */
            $callback = app($task->on_finish);
            $callback->handle($task->fresh());
        }
    }
}
