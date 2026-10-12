<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    public function manage(User $user, Server $server): bool
    {
        return $server->user_id === $user->id;
    }
}
