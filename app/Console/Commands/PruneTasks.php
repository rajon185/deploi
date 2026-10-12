<?php

namespace App\Console\Commands;

use App\Models\Deployment;
use App\Models\ServerTask;
use Illuminate\Console\Command;

class PruneTasks extends Command
{
    protected $signature = 'deploi:prune-tasks {--days=30}';

    protected $description = 'Delete finished server task logs older than N days (deployment logs are kept)';

    public function handle(): void
    {
        $count = ServerTask::where('created_at', '<', now()->subDays((int) $this->option('days')))
            ->whereNotIn('status', ['pending', 'running'])
            ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', '!=', (new Deployment)->getMorphClass()))
            ->delete();

        $this->info("Pruned {$count} task(s).");
    }
}
