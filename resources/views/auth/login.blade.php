<x-guest-layout title="Log in">
    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf
        <div>
            <label class="label" for="email">Email</label>
            <input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        </div>
        <div>
            <label class="label" for="password">Password</label>
            <input class="input" id="password" type="password" name="password" required>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" class="rounded"> Remember me</label>
        <button class="btn btn-primary w-full">Log in</button>
        @if($canRegister)
            <p class="text-center text-sm text-slate-500">First time here? <a href="{{ route('register') }}" class="font-medium text-indigo-600">Create the owner account</a></p>
        @endif
    </form>
</x-guest-layout>
