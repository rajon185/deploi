<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Create (or reset) a Deploi login from the command line - e.g. the first admin on a fresh
 * install, or getting back in after losing a password.
 *
 *   php artisan deploi:create-user --email=you@example.com --name="You"
 */
class CreateUser extends Command
{
    protected $signature = 'deploi:create-user
        {--email= : Login email}
        {--name= : Display name (defaults to the part of the email before @)}
        {--password= : Password (leave out to generate a strong one)}
        {--reset : If the email already exists, set a new password (and turn off 2FA)}';

    protected $description = 'Create a Deploi user (or reset an existing user\'s password)';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: Str::password(20, symbols: false);
        $generated = ! $this->option('password');

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user && ! $this->option('reset')) {
            $this->error("A user with {$email} already exists. Use --reset to set a new password.");

            return self::FAILURE;
        }

        if ($user) {
            // A reset is for getting back in, so also clear 2FA in case the authenticator was lost.
            $user->forceFill([
                'password' => $password,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_step' => null,
            ])->save();
            $this->info("Password reset for {$email} (two-factor authentication was turned off).");
        } else {
            User::create([
                'name' => $this->option('name') ?: Str::before($email, '@'),
                'email' => $email,
                'password' => $password,
            ]);
            $this->info("User {$email} created.");
        }

        if ($generated) {
            $this->line("Password: <comment>{$password}</comment>");
            $this->line('Log in and change it under Profile.');
        }

        return self::SUCCESS;
    }
}
