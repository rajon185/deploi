<x-layout title="Profile">
    <x-slot:header><h1 class="text-lg font-semibold">Profile</h1></x-slot:header>

    <form method="POST" action="{{ route('account.profile.update') }}" class="card max-w-xl space-y-4 p-6">
        @csrf @method('PUT')
        <div><label class="label">Name</label><input class="input" name="name" value="{{ old('name', $user->name) }}" required></div>
        <div><label class="label">Email</label><input class="input" type="email" name="email" value="{{ old('email', $user->email) }}" required><p class="hint">Also used for Let's Encrypt expiry notices.</p></div>
        <hr class="border-slate-200">
        <div><label class="label">Current password</label><input class="input" type="password" name="current_password" autocomplete="current-password"></div>
        <div><label class="label">New password</label><input class="input" type="password" name="password" autocomplete="new-password"></div>
        <div><label class="label">Confirm new password</label><input class="input" type="password" name="password_confirmation" autocomplete="new-password"></div>
        <button class="btn btn-primary">Save</button>
    </form>

    <div id="two-factor" class="card max-w-xl space-y-4 p-6">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold">Two-factor authentication</h2>
            @if($user->hasTwoFactorEnabled())
                <x-badge status="active">On</x-badge>
            @elseif($twoFactorSettingUp)
                <x-badge status="pending">Finishing setup</x-badge>
            @else
                <x-badge status="off">Off</x-badge>
            @endif
        </div>

        @if(session('recovery_codes'))
            <div class="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-medium text-amber-900">Save these recovery codes somewhere safe - they won't be shown again.</p>
                <p class="text-xs text-amber-800">If you lose your phone, each code lets you log in once.</p>
                @php($codes = implode("\n", session('recovery_codes')))
                <pre class="terminal">{{ $codes }}</pre>
                <button type="button" class="btn btn-secondary btn-sm" onclick="copyText(@js($codes), this)">Copy codes</button>
            </div>
        @endif

        @if($user->hasTwoFactorEnabled())
            <p class="text-sm text-slate-600">
                Logging in asks for a code from your authenticator app.
                {{ count($user->two_factor_recovery_codes ?? []) }} unused recovery {{ Str::plural('code', count($user->two_factor_recovery_codes ?? [])) }} left.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <form method="POST" action="{{ route('account.two-factor.recovery-codes') }}" class="space-y-2">
                    @csrf
                    <input class="input" type="password" name="current_password" placeholder="Current password" autocomplete="current-password" required>
                    <button class="btn btn-secondary w-full">New recovery codes</button>
                </form>
                <form method="POST" action="{{ route('account.two-factor.disable') }}" class="space-y-2">
                    @csrf @method('DELETE')
                    <input class="input" type="password" name="current_password" placeholder="Current password" autocomplete="current-password" required>
                    <button class="btn btn-danger w-full">Turn off 2FA</button>
                </form>
            </div>
        @elseif($twoFactorSettingUp)
            <ol class="list-inside list-decimal space-y-1 text-sm text-slate-600">
                <li>Open an authenticator app: Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden…</li>
                <li>Scan this QR code (or type the key in by hand).</li>
                <li>Enter the 6-digit code the app shows.</li>
            </ol>
            <div class="flex flex-wrap items-center gap-6">
                <div class="rounded-lg border border-slate-200 bg-white p-2">{!! $twoFactorQr !!}</div>
                <div class="min-w-0 flex-1 space-y-1 text-sm">
                    <p class="text-slate-500">Setup key</p>
                    <p class="font-mono text-xs break-all">{{ trim(chunk_split($user->two_factor_secret, 4, ' ')) }}</p>
                </div>
            </div>
            <form method="POST" action="{{ route('account.two-factor.confirm') }}" class="flex flex-wrap gap-3">
                @csrf
                <input class="input w-40 text-center font-mono tracking-[0.3em]" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456" required autofocus>
                <button class="btn btn-primary">Confirm &amp; turn on</button>
            </form>
            <form method="POST" action="{{ route('account.two-factor.disable') }}">
                @csrf @method('DELETE')
                <button class="text-sm text-slate-500 hover:text-red-600">Cancel setup</button>
            </form>
        @else
            <p class="text-sm text-slate-600">Protect your account with a 6-digit code from an authenticator app on your phone, as well as your password. This matters here: Deploi holds root access to your servers.</p>
            <form method="POST" action="{{ route('account.two-factor.enable') }}" class="flex flex-wrap gap-3">
                @csrf
                <input class="input max-w-xs" type="password" name="current_password" placeholder="Current password" autocomplete="current-password" required>
                <button class="btn btn-primary">Set up 2FA</button>
            </form>
        @endif
    </div>
</x-layout>
