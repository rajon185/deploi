<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /** Abort unless an account-level resource (provider, key, channel...) belongs to the current user. */
    protected function ensureOwns(object $model): void
    {
        abort_unless($model->user_id === auth()->id(), 404);
    }
}
