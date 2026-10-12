<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ServerTask extends Model
{
    protected $fillable = [
        'name', 'user', 'script', 'output', 'status', 'exit_code', 'timeout', 'on_finish', 'options',
        'subject_type', 'subject_id', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function successful(): bool
    {
        return $this->status === 'finished' && $this->exit_code === 0;
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['pending', 'running'], true);
    }

    public function duration(): ?string
    {
        if (! $this->started_at) {
            return null;
        }

        return $this->started_at->diffForHumans($this->finished_at ?? now(), true, true);
    }
}
