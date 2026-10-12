<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function sites(): HasManyThrough
    {
        return $this->hasManyThrough(Site::class, Server::class);
    }

    public function serverProviders(): HasMany
    {
        return $this->hasMany(ServerProvider::class);
    }

    public function sourceProviders(): HasMany
    {
        return $this->hasMany(SourceProvider::class);
    }

    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class);
    }

    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class);
    }

    public function backupDisks(): HasMany
    {
        return $this->hasMany(BackupDisk::class);
    }
}
