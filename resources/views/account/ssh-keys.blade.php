<x-layout title="SSH keys">
    <x-slot:header><h1 class="text-lg font-semibold">SSH keys</h1></x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            @if($keys->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">Save your public keys here, then add them to any server in one click from its SSH keys tab.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach($keys as $key)
                        <li class="flex items-center justify-between gap-4 px-5 py-3">
                            <div class="min-w-0"><p class="font-medium">{{ $key->name }}</p><p class="truncate font-mono text-xs text-slate-500">{{ $key->public_key }}</p></div>
                            <x-delete-button :action="route('account.ssh-keys.destroy', $key)" confirm="Remove this key?" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <form method="POST" action="{{ route('account.ssh-keys.store') }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="font-semibold">Add key</h2>
            <div><label class="label">Name</label><input class="input" name="name" value="{{ old('name') }}" placeholder="Work laptop" required></div>
            <div><label class="label">Public key</label><textarea class="input font-mono text-xs" rows="6" name="public_key" placeholder="ssh-ed25519 AAAA…" required>{{ old('public_key') }}</textarea></div>
            <button class="btn btn-primary w-full">Save key</button>
        </form>
    </div>
</x-layout>
