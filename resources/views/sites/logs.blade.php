<x-layout :title="'Logs · '.$site->domain">
    <x-site-header :site="$site" active="logs" />

    <div class="flex items-center justify-between">
        <div class="flex gap-2">
            @foreach($paths as $key => $path)
                <a href="{{ route('sites.logs', [$site, 'log' => $key]) }}" @class(['btn btn-sm', 'btn-primary' => $which === $key, 'btn-secondary' => $which !== $key])>{{ ucfirst($key) }}</a>
            @endforeach
        </div>
        <a href="{{ route('sites.logs', [$site, 'log' => $which]) }}" class="btn btn-secondary btn-sm">Refresh</a>
    </div>

    <p class="font-mono text-xs text-slate-500">{{ $paths[$which] }} (last 300 lines)</p>

    @if($error)
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</div>
    @else
        <pre class="terminal h-[36rem]" x-data x-init="$el.scrollTop = $el.scrollHeight">{{ trim($output) ?: 'Log is empty.' }}</pre>
    @endif
</x-layout>
