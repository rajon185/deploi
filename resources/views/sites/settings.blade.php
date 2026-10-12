<x-layout :title="'Settings · '.$site->domain">
    <x-site-header :site="$site" active="settings" />

    <div class="grid gap-6 lg:grid-cols-2">
        @if($site->imported)
        <form method="POST" action="{{ route('sites.update', $site) }}" class="card space-y-4 p-5">
            @csrf @method('PUT')
            <h2 class="font-semibold">Imported site</h2>
            <dl class="grid grid-cols-3 gap-y-1 rounded-md bg-slate-50 p-3 text-xs">
                <dt class="text-slate-500">Web server</dt><dd class="col-span-2">{{ ucfirst($site->webserver()) }}</dd>
                <dt class="text-slate-500">Config file</dt><dd class="col-span-2 font-mono break-all">{{ $site->meta['config_path'] ?? '—' }}</dd>
                <dt class="text-slate-500">Web root</dt><dd class="col-span-2 font-mono break-all">{{ $site->meta['root'] ?? '—' }}</dd>
                <dt class="text-slate-500">Domains</dt><dd class="col-span-2">{{ implode(', ', $site->allDomains()) }}</dd>
            </dl>
            <p class="text-xs text-slate-500">Deploi leaves this site's {{ ucfirst($site->webserver()) }} config alone. Change domains, redirects or PHP handling in that file directly{{ ! empty($site->meta['shared_config']) ? ' (note: it also contains other sites)' : '' }}.</p>
            <div><label class="label">Project directory</label><input class="input font-mono" name="directory" value="{{ old('directory', $site->path()) }}" required><p class="hint">Where deploy scripts, <span class="code">.env</span> and logs are read from.</p></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Run commands as</label><input class="input font-mono" name="system_user" value="{{ old('system_user', $site->system_user) }}" required></div>
                <div>
                    <label class="label">Project type</label>
                    <select class="input" name="project_type">@foreach(\App\Models\Site::PROJECT_TYPES as $k => $v)<option value="{{ $k }}" @selected($site->project_type === $k)>{{ $v }}</option>@endforeach</select>
                </div>
                <div><label class="label">PHP version</label><input class="input" name="php_version" value="{{ old('php_version', $site->php_version) }}" placeholder="8.3"><p class="hint">Used for <span class="code">$DEPLOI_PHP</span>.</p></div>
                <div><label class="label">Git branch</label><input class="input font-mono" name="branch" value="{{ old('branch', $site->branch) }}"></div>
            </div>
            <button class="btn btn-primary">Save</button>
        </form>
        @else
        <form method="POST" action="{{ route('sites.update', $site) }}" class="card space-y-4 p-5">
            @csrf @method('PUT')
            <h2 class="font-semibold">General</h2>
            <div><label class="label">Aliases</label><input class="input" name="aliases" value="{{ old('aliases', implode(', ', $site->aliases ?? [])) }}" placeholder="www.{{ $site->domain }}"></div>
            @if($site->project_type !== 'nodejs')
                <div><label class="label">Web directory</label><input class="input font-mono" name="web_directory" value="{{ old('web_directory', $site->web_directory) }}"></div>
            @else
                <div><label class="label">App port</label><input class="input" type="number" name="app_port" value="{{ old('app_port', $site->app_port) }}"></div>
            @endif
            @if($site->usesPhp())
                <div>
                    <label class="label">PHP version</label>
                    <select class="input" name="php_version">
                        @foreach($server->installed_php_versions ?? [] as $v)<option value="{{ $v }}" @selected($v === $site->phpVersion())>PHP {{ $v }}</option>@endforeach
                    </select>
                    <p class="hint">Remember to update your deploy script if it hard-codes a PHP binary.</p>
                </div>
            @endif
            @if($site->zero_downtime)
                <div><label class="label">Releases to keep</label><input class="input" type="number" name="keep_releases" value="{{ $site->keep_releases }}"></div>
            @endif
            <button class="btn btn-primary">Save &amp; update Nginx</button>
        </form>
        @endif

        <div class="space-y-6">
            @if($site->managesWebserverConfig())
            <div class="card space-y-4 p-5">
                <h2 class="font-semibold">Redirects</h2>
                @if($site->redirects->isNotEmpty())
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach($site->redirects as $redirect)
                            <li class="flex items-center justify-between py-2">
                                <span class="font-mono text-xs">{{ $redirect->from }} → {{ $redirect->to }} ({{ $redirect->type }})</span>
                                <x-delete-button :action="route('sites.redirects.destroy', [$site, $redirect->id])" label="×" confirm="Remove redirect?" />
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ route('sites.redirects.store', $site) }}" class="grid grid-cols-5 gap-2">
                    @csrf
                    <input class="input col-span-2" name="from" placeholder="/old-page" required>
                    <input class="input col-span-2" name="to" placeholder="/new-page" required>
                    <select class="input" name="type"><option>301</option><option>302</option></select>
                    <button class="btn btn-secondary col-span-5">Add redirect</button>
                </form>
            </div>
            @endif

            @if($site->isLaravel())
                <form method="POST" action="{{ route('sites.scheduler', $site) }}" class="card space-y-3 p-5">
                    @csrf
                    <h2 class="font-semibold">Laravel scheduler</h2>
                    <p class="text-sm text-slate-600">Adds a cron job running <span class="code">artisan schedule:run</span> every minute.</p>
                    <button class="btn btn-secondary">Enable scheduler</button>
                </form>
            @endif

            <form method="POST" action="{{ route('sites.destroy', $site) }}" class="card space-y-3 border-red-200 p-5">
                @csrf @method('DELETE')
                @if($site->imported)
                    <h2 class="font-semibold text-red-700">Remove from Deploi</h2>
                    <p class="text-sm text-slate-600">Stops managing this site in Deploi (and removes queue workers Deploi added). The site keeps running - its files and {{ ucfirst($site->webserver()) }} config are not touched. Type <span class="code">{{ $site->domain }}</span> to confirm.</p>
                    <input class="input" name="confirm" autocomplete="off">
                    <button class="btn btn-danger">Remove from Deploi</button>
                @else
                    <h2 class="font-semibold text-red-700">Delete site</h2>
                    <p class="text-sm text-slate-600">Removes the Nginx config, certificates, queue workers and all files in <span class="code">{{ $site->path() }}</span>. Type <span class="code">{{ $site->domain }}</span> to confirm.</p>
                    <input class="input" name="confirm" autocomplete="off">
                    <button class="btn btn-danger">Delete site</button>
                @endif
            </form>
        </div>
    </div>
</x-layout>
