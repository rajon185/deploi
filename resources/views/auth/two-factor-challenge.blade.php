<x-guest-layout title="Two-factor authentication">
    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
        <form method="POST" action="{{ route('two-factor.challenge') }}" class="space-y-4" x-show="!recovery">
            @csrf
            <p class="text-sm text-slate-600">Open your authenticator app and enter the 6-digit code for Deploi.</p>
            <div>
                <label class="label" for="code">Authentication code</label>
                <input class="input text-center font-mono text-lg tracking-[0.4em]" id="code" name="code"
                       inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" autofocus
                       x-bind:required="!recovery" x-bind:disabled="recovery">
            </div>
            <button class="btn btn-primary w-full">Verify</button>
            <button type="button" class="w-full text-center text-sm text-slate-500 hover:text-indigo-600" @click="recovery = true; $nextTick(() => $refs.recovery.focus())">Lost your phone? Use a recovery code</button>
        </form>

        <form method="POST" action="{{ route('two-factor.challenge') }}" class="space-y-4" x-show="recovery" x-cloak>
            @csrf
            <p class="text-sm text-slate-600">Enter one of the recovery codes you saved when you turned on two-factor authentication. Each code works once.</p>
            <div>
                <label class="label" for="recovery_code">Recovery code</label>
                <input class="input font-mono" id="recovery_code" name="recovery_code" x-ref="recovery" autocomplete="off" placeholder="abcde-12345"
                       x-bind:required="recovery" x-bind:disabled="!recovery">
            </div>
            <button class="btn btn-primary w-full">Verify</button>
            <button type="button" class="w-full text-center text-sm text-slate-500 hover:text-indigo-600" @click="recovery = false">Use an authentication code instead</button>
        </form>

        <p class="mt-4 text-center text-sm"><a href="{{ route('login') }}" class="text-slate-500 hover:text-indigo-600">Back to log in</a></p>
    </div>
</x-guest-layout>
