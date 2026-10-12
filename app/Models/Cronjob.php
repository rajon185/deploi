<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cronjob extends Model
{
    public const PRESETS = [
        '* * * * *' => 'Every minute',
        '*/5 * * * *' => 'Every 5 minutes',
        '0 * * * *' => 'Hourly',
        '0 0 * * *' => 'Nightly',
        '0 0 * * 0' => 'Weekly',
        '0 0 1 * *' => 'Monthly',
    ];

    protected $fillable = ['command', 'frequency', 'user', 'status'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function fileName(): string
    {
        return "deploi-cron-{$this->id}";
    }
}
