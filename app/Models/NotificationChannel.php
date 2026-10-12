<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationChannel extends Model
{
    public const TYPES = ['slack' => 'Slack', 'discord' => 'Discord', 'webhook' => 'Generic webhook (JSON POST)'];

    public const EVENTS = [
        'deployment.finished' => 'Deployment succeeded',
        'deployment.failed' => 'Deployment failed',
        'server.provisioned' => 'Server provisioned',
        'server.failed' => 'Server provisioning failed',
        'server.offline' => 'Server unreachable',
    ];

    protected $fillable = ['type', 'label', 'url', 'events'];

    protected $hidden = ['url'];

    protected function casts(): array
    {
        return ['url' => 'encrypted', 'events' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wants(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }
}
