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
</x-layout>
