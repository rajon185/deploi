<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SshKey extends Model
{
    protected $fillable = ['name', 'public_key'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
