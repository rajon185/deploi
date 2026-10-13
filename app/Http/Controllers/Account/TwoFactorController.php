<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\TwoFactor;
use Illuminate\Http\Request;

/**
 * Turning authenticator-app 2FA on and off. Setup is two-step: a secret is generated and shown
 * as a QR code, but 2FA is only enforced once the user confirms it with a valid code.
 */
class TwoFactorController extends Controller
{
    public function __construct(protected TwoFactor $twoFactor) {}

    public function enable(Request $request)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        $request->user()->forceFill([
            'two_factor_secret' => $this->twoFactor->generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return redirect()->to(route('account.profile').'#two-factor')->with('success', 'Scan the QR code with your authenticator app, then enter the 6-digit code to finish.');
    }

    public function confirm(Request $request)
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'string']]);

        abort_unless($user->two_factor_secret && ! $user->two_factor_confirmed_at, 422, 'Start setting up two-factor authentication first.');

        if (! $this->twoFactor->verify($user, $request->input('code'))) {
            return back()->withErrors(['code' => 'That code is not valid. Check the time on your phone is correct and try the newest code.']);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return redirect()->to(route('account.profile').'#two-factor')
            ->with('success', 'Two-factor authentication is on.')
            ->with('recovery_codes', $this->twoFactor->generateRecoveryCodes($user));
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        abort_unless($request->user()->hasTwoFactorEnabled(), 422);

        return redirect()->to(route('account.profile').'#two-factor')
            ->with('success', 'New recovery codes generated. The old ones no longer work.')
            ->with('recovery_codes', $this->twoFactor->generateRecoveryCodes($request->user()));
    }

    public function disable(Request $request)
    {
        // Cancelling an unfinished setup doesn't need the password; turning off working 2FA does.
        if ($request->user()->hasTwoFactorEnabled()) {
            $request->validate(['current_password' => ['required', 'current_password']]);
        }

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return redirect()->to(route('account.profile').'#two-factor')->with('success', 'Two-factor authentication is off.');
    }
}
