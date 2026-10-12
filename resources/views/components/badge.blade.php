@props(['status'])
@php
    $classes = match ($status) {
        'active', 'finished', 'installed', 'running-ok' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'failed', 'disconnected', 'timeout' => 'bg-red-50 text-red-700 ring-red-600/20',
        'running', 'provisioning', 'installing', 'creating', 'connecting', 'queued', 'deleting', 'pending' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'imported' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
        default => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    };
    $pulse = in_array($status, ['running', 'provisioning', 'installing', 'creating', 'connecting', 'queued', 'deleting']);
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>
    @if($pulse)<span class="size-1.5 animate-pulse rounded-full bg-current"></span>@endif
    {{ $slot->isEmpty() ? ucfirst($status ?? 'unknown') : $slot }}
</span>
