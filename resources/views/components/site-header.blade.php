@props(['site', 'active' => 'overview'])
@php
    $tabs = [
        'overview' => ['Overview', route('sites.show', $site)],
        'deploy-script' => ['Deploy script', route('sites.deploy-script', $site)],
        'environment' => ['Environment', route('sites.environment', $site)],
        'ssl' => ['SSL', route('sites.ssl', $site)],
    ];
    if ($site->isLaravel()) $tabs['queue'] = ['Queue', route('sites.queue', $site)];
    $tabs['logs'] = ['Logs', route('sites.logs', $site)];
    $tabs['settings'] = ['Settings', route('sites.settings', $site)];
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('servers.show', $site->server) }}" class="text-sm text-slate-500 hover:text-indigo-600">← {{ $site->server->name }}</a>
            <div class="mt-1 flex items-center gap-3">
                <h1 class="text-2xl font-semibold tracking-tight">{{ $site->domain }}</h1>
                <x-badge :status="$site->status" />
                @if($site->imported)<x-badge status="imported">Imported</x-badge>@endif
                @if($site->ssl_status === 'active')<x-badge status="active">SSL</x-badge>@endif
            </div>
            <p class="mt-1 flex flex-wrap gap-x-4 text-sm text-slate-500">
                <span>{{ \App\Models\Site::PROJECT_TYPES[$site->project_type] }}</span>
                @if($site->usesPhp())<span>PHP {{ $site->phpVersion() }}</span>@endif
                @if($site->repository)<span>{{ $site->repository }} @ {{ $site->branch }}</span>@endif
                @if($site->zero_downtime)<span>Zero-downtime</span>@endif
                <a href="http{{ $site->ssl_status === 'active' ? 's' : '' }}://{{ $site->domain }}" target="_blank" class="text-indigo-600 hover:underline">Visit ↗</a>
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
