<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Redirect extends Model
{
    protected $fillable = ['from', 'to', 'type'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
