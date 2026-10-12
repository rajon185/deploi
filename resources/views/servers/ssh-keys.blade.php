<x-layout :title="'SSH keys · '.$server->name">
    <x-server-header :server="$server" active="ssh-keys" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-x-auto lg:col-span-2">
            @if($keys->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">Add your public key to SSH in as <span class="code">{{ $server->system_user }}</span>.</p>
            @else
                <table class="table">
                    <thead><tr><th>Name</th><th>User</th><th>Key</th><th>Status</th><th></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach($keys as $key)
                        <tr>
                            <td>{{ $key->name }}</td>
                            <td class="text-xs">{{ $key->user }}</td>
                            <td class="max-w-xs truncate font-mono text-xs">{{ Str::limit($key->public_key, 50) }}</td>
                            <td><x-badge :status="$key->status" /></td>
                            <td class="text-right"><x-delete-button :action="route('servers.ssh-keys.destroy', [$server, $key])" confirm="Remove this key from the server?" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <form method="POST" action="{{ route('servers.ssh-keys.store', $server) }}" class="card space-y-4 p-5" x-data="{ saved: '' }">
            @csrf
            <h2 class="font-semibold">Add SSH key</h2>
            @if($accountKeys->isNotEmpty())
                <div>
                    <label class="label">From your account</label>
                    <select class="input" name="ssh_key_id" x-model="saved">
                        <option value="">Paste a new key…</option>
                        @foreach($accountKeys as $accountKey)<option value="{{ $accountKey->id }}">{{ $accountKey->name }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div x-show="!saved" class="space-y-4">
                <div><label class="label">Name</label><input class="input" name="name" value="{{ old('name') }}" placeholder="My laptop"></div>
                <div><label class="label">Public key</label><textarea class="input font-mono text-xs" rows="5" name="public_key" placeholder="ssh-ed25519 AAAA…">{{ old('public_key') }}</textarea></div>
            </div>
            <div><label class="label">User</label><select class="input" name="user"><option>{{ $server->system_user }}</option><option>root</option></select></div>
            <button class="btn btn-primary w-full">Add key</button>
        </form>
    </div>
</x-layout>
