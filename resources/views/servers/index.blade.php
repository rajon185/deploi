<x-layout title="Servers">
    <x-slot:header><h1 class="text-lg font-semibold">Servers</h1></x-slot:header>
    <x-slot:actions>
        <a href="{{ route('servers.create', ['import' => 1]) }}" class="btn btn-secondary">Import existing server</a>
        <a href="{{ route('servers.create') }}" class="btn btn-primary">New server</a>
    </x-slot:actions>

    @if($servers->isEmpty())
        <x-empty>
            No servers yet. <a href="{{ route('servers.create') }}" class="font-medium text-indigo-600">Create your first server</a>.
        </x-empty>
    @else
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Name</th><th>IP address</th><th>Provider</th><th>Type</th><th>Sites</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($servers as $server)
                        <tr class="hover:bg-slate-50">
                            <td><a href="{{ route('servers.show', $server) }}" class="font-medium text-slate-900 hover:text-indigo-600">{{ $server->name }}</a></td>
                            <td class="font-mono text-xs">{{ $server->ip_address ?? '—' }}</td>
                            <td>{{ \App\Services\Providers\ProviderManager::name($server->provider) }}</td>
                            <td>{{ Str::headline($server->type) }}</td>
                            <td>{{ $server->sites_count }}</td>
                            <td><x-badge :status="$server->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layout>
