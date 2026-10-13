<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login', ['canRegister' => $this->registrationOpen()]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::validate($credentials)) {
            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
        }

        $user = User::where('email', $credentials['email'])->firstOrFail();

        // Password was right, but 2FA users aren't logged in until they enter a code.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', $request->boolean('remember'));
            $request->session()->put('login.started_at', now()->timestamp);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function showTwoFactorChallenge(Request $request)
    {
        if (! $this->pendingTwoFactorUser($request)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function twoFactorChallenge(Request $request, TwoFactor $twoFactor)
    {
        $user = $this->pendingTwoFactorUser($request);

        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Your login expired - please sign in again.']);
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        $valid = filled($data['recovery_code'] ?? null)
            ? $twoFactor->useRecoveryCode($user, $data['recovery_code'])
            : $twoFactor->verify($user, $data['code'] ?? '');

        if (! $valid) {
            return back()->withErrors([filled($data['recovery_code'] ?? null) ? 'recovery_code' : 'code' => 'That code is not valid.']);
        }

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget(['login.id', 'login.started_at']);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        if (filled($data['recovery_code'] ?? null)) {
            $left = count($user->two_factor_recovery_codes ?? []);
            session()->flash('error', "You used a recovery code ({$left} left). If you lost your authenticator, turn 2FA off and on again under Profile to set up a new device.");
        }

        return redirect()->intended(route('dashboard'));
    }

    /** The user who passed the password step in the last 5 minutes, if any. */
    protected function pendingTwoFactorUser(Request $request): ?User
    {
        $id = $request->session()->get('login.id');
        $startedAt = (int) $request->session()->get('login.started_at');

        if (! $id || $startedAt < now()->subMinutes(5)->timestamp) {
            $request->session()->forget(['login.id', 'login.remember', 'login.started_at']);

            return null;
        }

        return User::find($id);
    }

    public function showRegister()
    {
        abort_unless($this->registrationOpen(), 404);

        return view('auth.register');
    }

    public function register(Request $request)
    {
        abort_unless($this->registrationOpen(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create($data);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** A self-hosted panel: registration closes once the first account exists, unless explicitly allowed. */
    protected function registrationOpen(): bool
    {
        return User::count() === 0 || config('deploi.allow_registration');
    }
}
