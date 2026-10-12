<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirewallRule extends Model
{
    protected $fillable = ['name', 'port', 'protocol', 'from', 'rule_type', 'system', 'status'];

    protected function casts(): array
    {
        return ['system' => 'boolean'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** The ufw rule arguments, e.g. "allow proto tcp from 1.2.3.4 to any port 3306". */
    public function ufwArguments(): string
    {
        $parts = [$this->rule_type];

        if ($this->protocol !== 'any') {
            $parts[] = 'proto '.$this->protocol;
        }

        $parts[] = 'from '.($this->from ?: 'any');
        $parts[] = 'to any port '.$this->port;

        return implode(' ', $parts);
    }
}
