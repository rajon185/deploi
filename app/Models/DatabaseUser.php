<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DatabaseUser extends Model
{
    protected $fillable = ['username', 'password', 'host', 'status'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function databases(): BelongsToMany
    {
        return $this->belongsToMany(Database::class);
    }
}
