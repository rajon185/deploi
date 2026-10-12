<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerSshKey extends Model
{
    protected $fillable = ['ssh_key_id', 'name', 'public_key', 'user', 'status'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** The key line as written to authorized_keys, with a marker comment so it can be removed later. */
    public function authorizedKeyLine(): string
    {
        $parts = preg_split('/\s+/', trim($this->public_key));

        return $parts[0].' '.($parts[1] ?? '').' '.$this->marker();
    }

    public function marker(): string
    {
        return "deploi-key-{$this->id}";
    }
}
