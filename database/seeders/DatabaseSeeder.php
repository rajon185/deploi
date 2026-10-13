<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Deploi has nothing to seed. Create your login by visiting /register (the first account
     * becomes the owner) or with: php artisan deploi:create-user --email=you@example.com
     */
    public function run(): void
    {
        $this->command?->warn('Nothing to seed. Create a login with: php artisan deploi:create-user --email=you@example.com');
    }
}
