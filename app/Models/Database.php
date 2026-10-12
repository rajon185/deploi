<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Database extends Model
{
    protected $fillable = ['name', 'status'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(DatabaseUser::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(DatabaseBackup::class);
    }
}
