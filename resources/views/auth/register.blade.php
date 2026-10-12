<x-guest-layout title="Create account">
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf
        <div>
            <label class="label" for="name">Name</label>
            <input class="input" id="name" name="name" value="{{ old('name') }}" required autofocus>
        </div>
        <div>
            <label class="label" for="email">Email</label>
            <input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required>
            <p class="hint">Also used as the Let's Encrypt account email.</p>
        </div>
        <div>
            <label class="label" for="password">Password</label>
            <input class="input" id="password" type="password" name="password" required>
        </div>
        <div>
            <label class="label" for="password_confirmation">Confirm password</label>
            <input class="input" id="password_confirmation" type="password" name="password_confirmation" required>
        </div>
        <button class="btn btn-primary w-full">Create account</button>
        <p class="text-center text-sm text-slate-500"><a href="{{ route('login') }}" class="font-medium text-indigo-600">Back to log in</a></p>
    </form>
</x-guest-layout>
