<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Daemon extends Model
{
    protected $fillable = ['command', 'directory', 'user', 'processes', 'stop_wait_seconds', 'status'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function programName(): string
    {
        return "daemon-{$this->id}";
    }
}
