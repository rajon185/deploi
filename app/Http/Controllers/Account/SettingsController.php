<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\BackupDisk;
use App\Models\NotificationChannel;
use App\Models\SshKey;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SettingsController extends Controller
{
    // ------------------------------------------------------------ Profile

    public function profile(TwoFactor $twoFactor)
    {
        $user = auth()->user();
        $settingUp = $user->two_factor_secret && ! $user->two_factor_confirmed_at;

        return view('account.profile', [
            'user' => $user,
            'twoFactorSettingUp' => $settingUp,
            'twoFactorQr' => $settingUp ? $twoFactor->qrCodeSvg($user, $user->two_factor_secret) : null,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        $user->fill(['name' => $data['name'], 'email' => $data['email']]);
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return back()->with('success', 'Profile updated.');
    }

    // ------------------------------------------------------------ SSH keys

    public function sshKeys()
    {
        return view('account.ssh-keys', ['keys' => auth()->user()->sshKeys()->latest()->get()]);
    }

    public function storeSshKey(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'public_key' => ['required', 'string', 'regex:/^(ssh-(rsa|ed25519|dss)|ecdsa-sha2-\S+|sk-\S+)\s+[A-Za-z0-9+\/=]+/'],
        ], ['public_key.regex' => 'That does not look like an OpenSSH public key.']);

        $request->user()->sshKeys()->create($data);

        return back()->with('success', 'SSH key saved. Add it to servers from each server\'s SSH Keys tab.');
    }

    public function destroySshKey(SshKey $key)
    {
        $this->ensureOwns($key);
        $key->delete();

        return back()->with('success', 'SSH key removed from your account (servers keep their copy until removed there).');
    }

    // ------------------------------------------------------------ Notifications

    public function notifications()
    {
        return view('account.notifications', ['channels' => auth()->user()->notificationChannels()->get()]);
    }

    public function storeNotificationChannel(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(NotificationChannel::TYPES))],
            'label' => ['required', 'string', 'max:60'],
            'url' => ['required', 'url:https', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(NotificationChannel::EVENTS))],
        ]);

        $request->user()->notificationChannels()->create($data);

        return back()->with('success', 'Notification channel added.');
    }

    public function destroyNotificationChannel(NotificationChannel $channel)
    {
        $this->ensureOwns($channel);
        $channel->delete();

        return back()->with('success', 'Notification channel removed.');
    }

    // ------------------------------------------------------------ Backup storage

    public function backupDisks()
    {
        return view('account.backup-disks', ['disks' => auth()->user()->backupDisks()->get()]);
    }

    public function storeBackupDisk(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'key' => ['required', 'string', 'max:255'],
            'secret' => ['required', 'string', 'max:255'],
            'bucket' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'region' => ['nullable', 'string', 'max:60'],
            'endpoint' => ['nullable', 'url', 'max:255'],
        ]);

        $request->user()->backupDisks()->create([
            'label' => $data['label'],
            'credentials' => collect($data)->only(['key', 'secret', 'bucket', 'region', 'endpoint'])->all(),
        ]);

        return back()->with('success', 'Backup storage added.');
    }

    public function destroyBackupDisk(BackupDisk $disk)
    {
        $this->ensureOwns($disk);
        $disk->delete();

        return back()->with('success', 'Backup storage removed.');
    }

    // ------------------------------------------------------------ API tokens

    public function apiTokens()
    {
        return view('account.api-tokens', ['tokens' => auth()->user()->tokens()->latest()->get()]);
    }

    public function storeApiToken(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $token = $request->user()->createToken($data['name']);

        return back()->with('token', $token->plainTextToken);
    }

    public function destroyApiToken(Request $request, int $token)
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return back()->with('success', 'Token revoked.');
    }
}
