<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupDisk extends Model
{
    protected $fillable = ['label', 'credentials'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
