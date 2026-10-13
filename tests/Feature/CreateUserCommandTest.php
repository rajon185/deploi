<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user_with_a_generated_password(): void
    {
        $this->artisan('deploi:create-user', ['--email' => 'admin@example.com'])
            ->expectsOutputToContain('User admin@example.com created.')
            ->expectsOutputToContain('Password:')
            ->assertSuccessful();

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame('admin', $user->name);

        // The first account closes registration, just like signing up through the UI
        $this->get('/register')->assertNotFound();
    }

    public function test_it_uses_a_given_password_and_refuses_duplicates_without_reset(): void
    {
        $this->artisan('deploi:create-user', ['--email' => 'me@example.com', '--name' => 'Me', '--password' => 'correct-horse-battery'])->assertSuccessful();
        $this->assertTrue(Hash::check('correct-horse-battery', User::firstOrFail()->password));

        $this->artisan('deploi:create-user', ['--email' => 'me@example.com', '--password' => 'another-password'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->artisan('deploi:create-user', ['--email' => 'not-an-email', '--password' => 'whatever123'])->assertFailed();
        $this->artisan('deploi:create-user', ['--email' => 'x@example.com', '--password' => 'short'])->assertFailed();
    }

    public function test_reset_sets_a_new_password_and_clears_two_factor(): void
    {
        $user = User::create(['name' => 'Me', 'email' => 'me@example.com', 'password' => 'old-password']);
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        $this->artisan('deploi:create-user', ['--email' => 'me@example.com', '--password' => 'brand-new-pass', '--reset' => true])
            ->expectsOutputToContain('two-factor authentication was turned off')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $user->password));
        $this->assertFalse($user->hasTwoFactorEnabled());
    }
}
