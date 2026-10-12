<?php

namespace App\Models;

use App\Services\SourceControl\SourceControlDriver;
use App\Services\SourceControl\SourceControlManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceProvider extends Model
{
    protected $fillable = ['provider', 'label', 'username', 'token', 'url'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): SourceControlDriver
    {
        return SourceControlManager::make($this);
    }
}
