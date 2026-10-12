<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueWorker extends Model
{
    protected $fillable = ['connection', 'queue', 'processes', 'timeout', 'sleep', 'tries', 'memory', 'status'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function programName(): string
    {
        return "worker-{$this->id}";
    }

    public function command(): string
    {
        $php = 'php'.$this->site->phpVersion();

        return sprintf(
            '%s %s/artisan queue:work %s --queue=%s --sleep=%d --tries=%d --timeout=%d --memory=%d',
            $php, $this->site->currentPath(), $this->connection, $this->queue,
            $this->sleep, $this->tries, $this->timeout, $this->memory,
        );
    }
}
