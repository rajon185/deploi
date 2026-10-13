<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Scripts\PhpScripts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
    }

    protected function codeFor(User $user, int $offsetSteps = 0): string
    {
        $g = new Google2FA;

        return $g->oathTotp($user->fresh()->two_factor_secret, $g->getTimestamp() + $offsetSteps);
    }

    /** Set up and confirm 2FA through the real UI routes; returns the recovery codes. */
    protected function enableTwoFactor(): array
    {
        $this->actingAs($this->user)->post('/account/two-factor', ['current_password' => 'password'])->assertRedirect();
        $this->get('/account/profile')->assertOk()->assertSee('Scan this QR code')->assertSee('<svg', false);

        $response = $this->post('/account/two-factor/confirm', ['code' => $this->codeFor($this->user)]);
        $response->assertSessionHas('success', 'Two-factor authentication is on.');

        $this->assertTrue($this->user->fresh()->hasTwoFactorEnabled());
        $codes = $response->getSession()->get('recovery_codes');

        auth()->logout();
        $this->flushSession();

        return $codes;
    }

    public function test_setup_requires_password_and_a_valid_code(): void
    {
        $this->actingAs($this->user)->post('/account/two-factor', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertNull($this->user->fresh()->two_factor_secret);

        $this->post('/account/two-factor', ['current_password' => 'password']);
        $user = $this->user->fresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotSame($user->two_factor_secret, $user->getRawOriginal('two_factor_secret'), 'secret must be encrypted at rest');
        $this->assertFalse($user->hasTwoFactorEnabled(), 'not enforced until confirmed');

        $this->post('/account/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());

        // An unconfirmed setup doesn't affect logging in
        auth()->logout();
        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'password'])->assertRedirect('/');
    }

    public function test_login_requires_a_code_once_enabled(): void
    {
        $recoveryCodes = $this->enableTwoFactor();
        $this->assertCount(8, $recoveryCodes);

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');

        $this->get('/two-factor-challenge')->assertOk()->assertSee('Authentication code');
        $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => $this->codeFor($this->user, 1)])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $this->enableTwoFactor();
        // The confirm step consumed the current code; the next window's code is still fresh.
        $code = $this->codeFor($this->user, 1);

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/');

        auth()->logout();
        $this->flushSession();

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_codes_work_once(): void
    {
        $codes = $this->enableTwoFactor();

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => strtoupper($codes[0])])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->user);
        $this->assertCount(7, $this->user->fresh()->two_factor_recovery_codes);

        auth()->logout();
        $this->flushSession();

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_the_challenge_expires(): void
    {
        $this->enableTwoFactor();

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'password']);
        $this->travel(6)->minutes();

        $this->post('/two-factor-challenge', ['code' => $this->codeFor($this->user)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_disable_requires_password(): void
    {
        $this->enableTwoFactor();
        $this->actingAs($this->user);

        $this->delete('/account/two-factor', ['current_password' => 'nope'])->assertSessionHasErrors('current_password');
        $this->assertTrue($this->user->fresh()->hasTwoFactorEnabled());

        $this->delete('/account/two-factor', ['current_password' => 'password'])->assertSessionHas('success');
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());
        $this->assertNull($this->user->fresh()->two_factor_secret);
    }

    public function test_php_85_is_offered_and_installs_without_missing_packages(): void
    {
        $this->assertSame('8.5', Server::PHP_VERSIONS[0]);

        $script = PhpScripts::installTask(new Server(['system_user' => 'deploi']), '8.5');
        $this->assertStringContainsString('PHP_PACKAGES="php8.5-fpm php8.5-cli php8.5-common"', $script);
        $this->assertStringContainsString('apt-cache show "php8.5-$EXT"', $script);
        $this->assertStringNotContainsString('install php8.5-opcache', $script);

        $file = tempnam(sys_get_temp_dir(), 'deploi');
        file_put_contents($file, $script);
        exec('bash -n '.escapeshellarg($file).' 2>&1', $out, $code);
        unlink($file);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
