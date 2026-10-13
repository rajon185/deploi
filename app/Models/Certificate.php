<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    protected $fillable = ['type', 'cert_name', 'domains', 'certificate', 'private_key', 'active', 'status', 'expires_at'];

    protected $hidden = ['certificate', 'private_key'];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'certificate' => 'encrypted',
            'private_key' => 'encrypted',
            'active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Directory on the server holding fullchain.pem / privkey.pem for this certificate. */
    public function path(): string
    {
        return $this->type === 'letsencrypt'
            ? '/etc/letsencrypt/live/'.$this->name()
            : '/etc/nginx/ssl/'.$this->name();
    }

    public function name(): string
    {
        return $this->cert_name ?: $this->site->domain.'-'.$this->id;
    }
}
