<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function manage(User $user, Site $site): bool
    {
        return $site->server->user_id === $user->id;
    }
}
