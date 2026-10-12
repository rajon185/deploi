<x-layout :title="'Discover · '.$server->name">
    <x-server-header :server="$server" active="discover">
        <form method="POST" action="{{ route('servers.discover.scan', $server) }}" x-data="{ busy: false }" @submit="busy = true">
            @csrf
            <button class="btn btn-primary" :disabled="busy" x-text="busy ? 'Scanning…' : '{{ $discovery ? 'Scan again' : 'Scan server' }}'">{{ $discovery ? 'Scan again' : 'Scan server' }}</button>
        </form>
    </x-server-header>

    @if(! $discovery)
        <x-empty>
            <p class="font-medium text-slate-700">See what's already running on this server.</p>
            <p class="mt-1">Deploi reads (and never changes) your Nginx/Apache sites, PHP versions, databases, cron jobs, Supervisor programs and firewall rules, then lets you import sites and databases to manage them here.</p>
        </x-empty>
    @else
        <p class="text-sm text-slate-500">
            Last scanned {{ \Illuminate\Support\Carbon::parse($discovery['scanned_at'])->diffForHumans() }}
            @if($discovery['hostname']) · host <span class="code">{{ $discovery['hostname'] }}</span>@endif
            @if($discovery['os']) · {{ $discovery['os'] }}@endif
        </p>

        {{-- Software --}}
        @php
            $sw = $discovery['software'];
            $items = [
                'Nginx' => $sw['nginx'] ?? null,
                'Apache' => $sw['apache'] ?? null,
                'PHP (CLI)' => $sw['php_cli'] ?? null,
                'PHP-FPM' => ! empty($sw['php_fpm']) ? implode(', ', $sw['php_fpm']) : null,
                ($discovery['database_type'] === 'mariadb' ? 'MariaDB' : 'MySQL') => isset($sw['mysql']) ? preg_replace('/^.*?Ver\s+(\S+).*$/', '$1', $sw['mysql']) : null,
                'PostgreSQL' => isset($sw['postgresql']) ? preg_replace('/^.*?(\d+(\.\d+)*).*$/', '$1', $sw['postgresql']) : null,
                'Redis' => $sw['redis'] ?? null,
                'Node.js' => $sw['node'] ?? null,
                'Composer' => ($sw['composer'] ?? false) ? 'installed' : null,
                'Supervisor' => ($sw['supervisor'] ?? false) ? 'installed' : null,
                'Certbot' => ($sw['certbot'] ?? false) ? 'installed' : null,
                'UFW firewall' => $discovery['ufw'] === 'missing' ? null : $discovery['ufw'],
            ];
        @endphp
        <div class="card p-5">
            <h2 class="mb-4 font-semibold">Installed software</h2>
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-4">
                @foreach($items as $label => $value)
                    <div>
                        <dt class="text-xs text-slate-500 uppercase">{{ $label }}</dt>
                        <dd @class(['font-medium', 'text-slate-400' => ! $value])>{{ $value ?: 'not installed' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Sites --}}
        <form method="POST" action="{{ route('servers.discover.sites', $server) }}" class="card overflow-x-auto" x-data="{ selected: [] }">
            @csrf
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
                <div>
                    <h2 class="font-semibold">Websites ({{ count($discovery['sites']) }})</h2>
                    <p class="text-xs text-slate-500">Found in the Nginx/Apache configs. Importing never changes their config or files.</p>
                </div>
                <button class="btn btn-primary btn-sm" :disabled="!selected.length" x-text="selected.length ? 'Import ' + selected.length + ' selected' : 'Select sites to import'">Import selected</button>
            </div>
            @if(empty($discovery['sites']))
                <p class="px-5 py-6 text-sm text-slate-500">No websites found in /etc/nginx or /etc/apache2.</p>
            @else
                <table class="table">
                    <thead><tr><th></th><th>Site</th><th>Directory</th><th>Type</th><th>Git</th><th>SSL</th><th>Owner</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach($discovery['sites'] as $domain => $found)
                        @php($managedSite = $managedSites[$domain] ?? null)
                        @php($managed = $managedSite !== null)
                        <tr @class(['bg-slate-50/60' => $managed])>
                            <td class="w-8">
                                @unless($managed)
                                    <input type="checkbox" name="sites[]" value="{{ $domain }}" x-model="selected" class="rounded">
                                @endunless
                            </td>
                            <td>
                                <a href="http{{ $found['ssl'] ? 's' : '' }}://{{ $domain }}" target="_blank" class="font-medium text-slate-900 hover:text-indigo-600">{{ $domain }}</a>
                                @if($found['aliases'])<p class="text-xs text-slate-500">{{ implode(', ', $found['aliases']) }}</p>@endif
                                <p class="font-mono text-[11px] text-slate-400" title="{{ $found['config_path'] }}">{{ ucfirst($found['webserver']) }} · {{ $found['config_path'] }}</p>
                                @if($managed)
                                    <a href="{{ route('sites.show', $managedSite) }}" class="text-xs font-medium text-emerald-700">✔ Managed in Deploi →</a>
                                @endif
                            </td>
                            <td class="font-mono text-xs">
                                {{ $found['directory'] ?? $found['root'] ?? '—' }}
                                @if(($found['web_directory'] ?? '/') !== '/')<span class="text-slate-400">(web: {{ $found['web_directory'] }})</span>@endif
                                @if(isset($found['exists']) && ! $found['exists'])<p class="font-sans text-red-600">Directory missing</p>@endif
                                @if($found['app_port'])<p class="font-sans text-slate-500">→ 127.0.0.1:{{ $found['app_port'] }}</p>@endif
                            </td>
                            <td class="text-xs whitespace-nowrap">
                                {{ \App\Models\Site::PROJECT_TYPES[$found['project_type'] ?? 'static'] ?? 'Unknown' }}
                                @if($found['php_version'])<p class="text-slate-500">PHP {{ $found['php_version'] }}</p>@endif
                            </td>
                            <td class="max-w-[14rem] text-xs">
                                @if(! empty($found['repository']))
                                    <span class="block truncate font-mono" title="{{ $found['repository'] }}">{{ $found['repository'] }}</span>
                                    @if($found['branch'])<span class="text-slate-500">branch {{ $found['branch'] }}</span>@endif
                                @else
                                    <span class="text-slate-400">not a git checkout</span>
                                @endif
                            </td>
                            <td class="text-xs whitespace-nowrap">
                                @if($found['ssl'])
                                    <x-badge status="active">HTTPS</x-badge>
                                    @if($found['ssl_expires'] ?? null)
                                        @php($expires = \Illuminate\Support\Carbon::parse($found['ssl_expires']))
                                        <p @class(['mt-1', 'text-red-600' => $expires->isPast() || $expires->diffInDays() < 14, 'text-slate-500' => ! ($expires->isPast() || $expires->diffInDays() < 14)])>{{ $expires->isPast() ? 'expired' : 'expires' }} {{ $expires->toFormattedDateString() }}</p>
                                    @endif
                                @else
                                    <span class="text-slate-400">HTTP only</span>
                                @endif
                            </td>
                            <td class="text-xs">{{ $found['owner'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </form>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Databases --}}
            <form method="POST" action="{{ route('servers.discover.databases', $server) }}" class="card" x-data="{ selected: [] }">
                @csrf
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                    <h2 class="font-semibold">Databases ({{ count($discovery['databases']) }})</h2>
                    @if($discovery['databases'])
                        <button class="btn btn-secondary btn-sm" :disabled="!selected.length">Import selected</button>
                    @endif
                </div>
                @if(empty($discovery['databases']))
                    <p class="px-5 py-6 text-sm text-slate-500">
                        No databases found{{ $discovery['database_type'] ? ' (Deploi lists MySQL/MariaDB as root over the local socket - if root uses a password, databases can\'t be listed)' : '' }}.
                    </p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach($discovery['databases'] as $db)
                            @php($managed = in_array($db['name'], $managedDatabases, true))
                            <li class="flex items-center gap-3 px-5 py-2">
                                @if($managed)
                                    <span class="w-4 text-emerald-600">✔</span>
                                @else
                                    <input type="checkbox" name="databases[]" value="{{ $db['name'] }}" x-model="selected" class="rounded">
                                @endif
                                <span class="font-mono text-xs">{{ $db['name'] }}</span>
                                <span class="text-xs text-slate-400">{{ $db['engine'] }}</span>
                                @if($managed)<span class="ml-auto text-xs text-emerald-700">in Deploi</span>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </form>

            {{-- Users --}}
            <div class="card">
                <div class="border-b border-slate-200 px-5 py-3 font-semibold">System users</div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse($discovery['users'] as $u)
                        <li class="flex items-center justify-between px-5 py-2">
                            <span class="font-mono text-xs">{{ $u['name'] }} @if($u['name'] === $server->system_user)<x-badge status="active">deploy user</x-badge>@endif</span>
                            <span class="text-xs text-slate-500">{{ $u['home'] }} · {{ basename($u['shell']) }}</span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-slate-500">Only system accounts.</li>
                    @endforelse
                </ul>
            </div>

            {{-- Cron --}}
            <div class="card">
                <div class="border-b border-slate-200 px-5 py-3 font-semibold">Scheduled jobs (cron)</div>
                @forelse($discovery['cron'] as $cron)
                    <div class="border-b border-slate-100 px-5 py-3 last:border-0">
                        <p class="mb-1 font-mono text-[11px] text-slate-500">{{ $cron['source'] }}</p>
                        @foreach($cron['lines'] as $line)
                            <p class="truncate font-mono text-xs" title="{{ $line }}">{{ $line }}</p>
                        @endforeach
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-slate-500">No cron jobs.</p>
                @endforelse
            </div>

            {{-- Supervisor --}}
            <div class="card">
                <div class="border-b border-slate-200 px-5 py-3 font-semibold">Background processes (Supervisor)</div>
                @php($programs = collect($discovery['supervisor'])->flatMap(fn ($f) => collect($f['programs'])->map(fn ($p, $name) => $p + ['name' => $name, 'file' => $f['path']])))
                @forelse($programs as $program)
                    <div class="border-b border-slate-100 px-5 py-3 last:border-0">
                        <p class="text-sm font-medium">{{ $program['name'] }} <span class="text-xs font-normal text-slate-500">· {{ $program['user'] ?? 'root' }} · ×{{ $program['numprocs'] }}</span></p>
                        <p class="truncate font-mono text-xs text-slate-600" title="{{ $program['command'] }}">{{ $program['command'] }}</p>
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-slate-500">{{ ($discovery['software']['supervisor'] ?? false) ? 'No programs configured.' : 'Supervisor is not installed.' }}</p>
                @endforelse
            </div>
        </div>

        {{-- Firewall --}}
        <div class="card p-5">
            <h2 class="mb-3 font-semibold">Firewall (UFW)</h2>
            @if($discovery['ufw'] === 'missing')
                <p class="text-sm text-slate-500">UFW is not installed - rules added in Deploi's Firewall tab will fail until it is (<span class="code">apt install ufw</span>).</p>
            @else
                <pre class="terminal max-h-72">{{ $discovery['firewall'] ?: 'Status: '.$discovery['ufw'] }}</pre>
            @endif
        </div>
    @endif
</x-layout>
