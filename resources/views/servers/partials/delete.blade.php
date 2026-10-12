<form method="POST" action="{{ route('servers.destroy', $server) }}" class="card space-y-3 border-red-200 p-5">
    @csrf @method('DELETE')
    <h2 class="font-semibold text-red-700">Delete server</h2>
    <p class="text-sm text-slate-600">Removes the server from Deploi. Type <span class="code">{{ $server->name }}</span> to confirm.</p>
    <input class="input" name="confirm" placeholder="{{ $server->name }}" autocomplete="off">
    @if($server->serverProvider && $server->provider_server_id)
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="delete_at_provider" value="1" class="rounded">
            Also destroy it at {{ \App\Services\Providers\ProviderManager::name($server->provider) }} (irreversible)
        </label>
    @endif
    <button class="btn btn-danger">Delete server</button>
</form>
