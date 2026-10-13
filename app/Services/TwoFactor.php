<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Authenticator-app (TOTP) two-factor authentication. */
class TwoFactor
{
    public function __construct(protected Google2FA $google2fa = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /** The otpauth:// URL authenticator apps read from the QR code. */
    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(config('app.name', 'Deploi'), $user->email, $secret);
    }

    public function qrCodeSvg(User $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($this->otpauthUrl($user, $secret));
    }

    /**
     * Check a 6-digit code. Codes from the previous/next 30s window are accepted for clock drift,
     * and a code (time step) that was already used is rejected.
     */
    public function verify(User $user, string $code, ?string $secret = null): bool
    {
        $secret ??= $user->two_factor_secret;
        $code = preg_replace('/\s+/', '', $code);

        if (! $secret || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // Passing 0 (not null) for "no previous step" makes the library return the matched step.
        $step = $this->google2fa->verifyKeyNewer($secret, $code, $user->two_factor_last_step ?? 0, 1);

        if ($step === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }

    /** @return list<string> plain-text codes, shown to the user once */
    public function generateRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();

        $user->forceFill(['two_factor_recovery_codes' => array_map([$this, 'hashCode'], $codes)])->save();

        return $codes;
    }

    /** Use (and burn) a recovery code. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $hash = $this->hashCode(Str::lower(trim($code)));
        $remaining = $user->two_factor_recovery_codes ?? [];

        foreach ($remaining as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($remaining[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();

                return true;
            }
        }

        return false;
    }

    /** Recovery codes are random, so a keyed hash is enough (and quick to compare). */
    protected function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, config('app.key'));
    }
}
