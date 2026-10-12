<?php

namespace App\Models;

use App\Services\Providers\ProviderManager;
use App\Services\Providers\ServerProviderDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerProvider extends Model
{
    protected $fillable = ['provider', 'label', 'credentials'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function driver(): ServerProviderDriver
    {
        return ProviderManager::make($this->provider, $this->credentials);
    }
}
