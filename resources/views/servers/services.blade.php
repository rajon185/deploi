<x-layout :title="'PHP & services · '.$server->name">
    <x-server-header :server="$server" active="services" />

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="card">
            <div class="border-b border-slate-200 px-5 py-3 font-semibold">Services</div>
            <ul class="divide-y divide-slate-100">
                @foreach($services as $service => $label)
                    <li class="flex items-center justify-between px-5 py-3">
                        <div><p class="font-medium">{{ $label }}</p><p class="font-mono text-xs text-slate-500">{{ $service }}</p></div>
                        <div class="flex gap-2">
                            @foreach(['restart', 'stop', 'start'] as $action)
                                <form method="POST" action="{{ route('servers.services.action', [$server, $service, $action]) }}" @if($action === 'stop') onsubmit="return confirm('Stop {{ $label }}?')" @endif>
                                    @csrf
                                    <button class="btn btn-secondary btn-sm">{{ ucfirst($action) }}</button>
                                </form>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        @if($server->hasPhp())
            <div class="card">
                <div class="border-b border-slate-200 px-5 py-3 font-semibold">PHP versions</div>
                <ul class="divide-y divide-slate-100">
                    @foreach(\App\Models\Server::PHP_VERSIONS as $version)
                        @php($installed = in_array($version, $server->installed_php_versions ?? [], true))
                        <li class="flex items-center justify-between px-5 py-3">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">PHP {{ $version }}</span>
                                @if($server->php_version === $version)<x-badge status="active">CLI default</x-badge>@endif
                            </div>
                            <div class="flex gap-2">
                                @if($installed)
                                    @if($server->php_version !== $version)
                                        <form method="POST" action="{{ route('servers.php.cli', $server) }}">@csrf<input type="hidden" name="version" value="{{ $version }}"><button class="btn btn-secondary btn-sm">Make CLI default</button></form>
                                        <x-delete-button :action="route('servers.php.uninstall', [$server, $version])" label="Uninstall" :confirm="'Uninstall PHP '.$version.'?'" class="btn btn-secondary btn-sm !text-red-600" />
                                    @endif
                                @else
                                    <form method="POST" action="{{ route('servers.php.install', $server) }}">@csrf<input type="hidden" name="version" value="{{ $version }}"><button class="btn btn-secondary btn-sm">Install</button></form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                    The CLI default only changes the plain <span class="code">php</span> command (and the default for new sites).
                    Each site keeps its own version - used by Nginx and by <span class="code">$DEPLOI_PHP</span> / <span class="code">$DEPLOI_COMPOSER</span> in deploys - so change it under Site → Settings.
                    Packages come from the ondrej/php PPA.
                </p>
                @php($sitesOnPhp = $server->sites()->get()->groupBy(fn ($s) => $s->phpVersion())->map->count())
                @if($sitesOnPhp->isNotEmpty())
                    <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                        Sites by PHP version: {{ $sitesOnPhp->sortKeysDesc()->map(fn ($n, $v) => "PHP {$v}: {$n}")->join(' · ') }}
                    </p>
                @endif
            </div>
        @endif
    </div>
</x-layout>
