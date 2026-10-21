@props(['server', 'active' => 'overview'])
@php
    $tabs = ['overview' => ['Overview', route('servers.show', $server)]];
    if ($server->isActive() || $server->status === 'disconnected') {
        if ($server->supportsDatabases()) $tabs['databases'] = ['Databases', route('servers.databases', $server)];
        $tabs['cronjobs'] = ['Cron jobs', route('servers.cronjobs', $server)];
        $tabs['daemons'] = ['Daemons', route('servers.daemons', $server)];
        $tabs['firewall'] = ['Firewall', route('servers.firewall', $server)];
        $tabs['ssh-keys'] = ['SSH keys', route('servers.ssh-keys', $server)];
        $tabs['services'] = ['PHP & services', route('servers.services', $server)];
        $tabs['discover'] = ['Discover', route('servers.discover', $server)];
    }
    $tabs['tasks'] = ['Task log', route('servers.tasks', $server)];
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-semibold tracking-tight">{{ $server->name }}</h1>
                <x-badge :status="$server->status" />
                @if($server->imported)<x-badge status="imported">Imported</x-badge>@endif
            </div>
            <p class="mt-1 flex flex-wrap gap-x-4 text-sm text-slate-500">
                <span>{{ $server->ip_address ?? 'No IP yet' }}</span>
                <span>{{ \App\Services\Providers\ProviderManager::name($server->provider) }}{{ $server->region ? ' · '.$server->region : '' }}</span>
                @if($server->php_version)<span>PHP {{ $server->phpRelease($server->php_version) }}</span>@endif
                @foreach($server->databaseEngines() as $engine)<span>{{ \App\Models\Server::DATABASES[$engine] ?? $engine }}</span>@endforeach
            </p>
        </div>
        {{ $slot }}
    </div>
    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200 text-sm">
        @foreach($tabs as $key => [$label, $url])
            <a href="{{ $url }}" @class([
                'border-b-2 px-3 py-2 font-medium whitespace-nowrap',
                'border-indigo-500 text-indigo-600' => $active === $key,
                'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $active !== $key,
            ])>{{ $label }}</a>
        @endforeach
    </nav>
</div>
