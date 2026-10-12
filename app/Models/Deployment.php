<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $fillable = [
        'server_task_id', 'status', 'triggered_by', 'commit_hash', 'commit_message', 'commit_author', 'release',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ServerTask::class, 'server_task_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'finished' => 'green',
            'failed' => 'red',
            default => 'amber',
        };
    }
}
