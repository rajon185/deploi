<x-layout :title="$server->name">
    <x-slot:header><span class="text-sm text-slate-500">Servers / {{ $server->name }}</span></x-slot:header>

    <x-server-header :server="$server" active="overview">
        @if($server->isActive())
            <form method="POST" action="{{ route('servers.reboot', $server) }}" onsubmit="return confirm('Reboot {{ $server->name }}? Sites will be unavailable for a minute.')">
                @csrf
                <button class="btn btn-secondary">Reboot</button>
            </form>
        @endif
    </x-server-header>

    {{-- Custom server waiting for its key --}}
    @if($server->status === 'pending')
        <div class="card space-y-4 p-6">
            <h2 class="text-lg font-semibold">Authorise Deploi on your server</h2>
            <p class="text-sm text-slate-600">
                @if($server->imported)
                    Log in to <span class="code">{{ $server->ip_address }}</span> as <strong>root</strong> and run the command below. This only adds Deploi's key - nothing else on the server changes.
                @else
                    Log in to <span class="code">{{ $server->ip_address }}</span> as <strong>root</strong> (a fresh Ubuntu 22.04 or 24.04 install) and run:
                @endif
            </p>
            @php($cmd = 'mkdir -p /root/.ssh && echo "'.trim($server->public_key).'" >> /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys')
            <div class="relative">
                <pre class="terminal pr-20">{{ $cmd }}</pre>
                <button type="button" onclick="copyText(@js($cmd), this)" class="btn btn-secondary btn-sm absolute top-2 right-2">Copy</button>
            </div>
            <details class="text-sm text-slate-600">
                <summary class="cursor-pointer font-medium">Testing with the local Docker sandbox?</summary>
                <div class="mt-2 space-y-2">
                    <p>Start the sandbox (<span class="code">docker compose --profile sandbox up -d sandbox</span>), then run this from the project folder:</p>
                    @php($dockerCmd = 'docker compose exec sandbox bash -c \'echo "'.trim($server->public_key).'" >> /root/.ssh/authorized_keys\'')
                    <div class="relative">
                        <pre class="terminal pr-20">{{ $dockerCmd }}</pre>
                        <button type="button" onclick="copyText(@js($dockerCmd), this)" class="btn btn-secondary btn-sm absolute top-2 right-2">Copy</button>
                    </div>
                </div>
            </details>
            <details class="text-sm text-slate-600">
                <summary class="cursor-pointer font-medium">Server only allows a non-root user (AWS, some VPS hosts)?</summary>
                <p class="mt-2">Log in as that user and run the command above with <span class="code">sudo bash -c '…'</span>, and make sure <span class="code">PermitRootLogin prohibit-password</span> is set in <span class="code">/etc/ssh/sshd_config</span>.</p>
            </details>
            @if($server->imported)
                <form method="POST" action="{{ route('servers.discover.scan', $server) }}" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <button class="btn btn-primary" :disabled="busy" x-text="busy ? 'Connecting and scanning…' : 'I\'ve added the key - connect and scan'">I've added the key - connect and scan</button>
                    <p class="hint">Read-only: Deploi lists the web server, PHP, databases, sites, cron jobs and firewall rules it finds. You then choose what to manage.</p>
                </form>
            @else
                <form method="POST" action="{{ route('servers.provision', $server) }}">
                    @csrf
                    <button class="btn btn-primary">I've added the key - start provisioning</button>
                </form>
            @endif
        </div>
    @endif

    {{-- Creating / provisioning --}}
    @if(in_array($server->status, ['creating', 'connecting', 'provisioning']))
        <div class="card p-6" x-data="serverStatus(@js(route('servers.status', $server)), @js($server->only(['status', 'provision_step', 'provision_progress'])))">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Setting up your server</h2>
                <span class="text-sm text-slate-500" x-text="(provision_progress || 0) + '%'"></span>
            </div>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-indigo-500 transition-all duration-700" :style="'width:' + Math.max(provision_progress || 0, 3) + '%'"></div>
            </div>
            <p class="mt-3 text-sm text-slate-600" x-text="provision_step || 'Queued…'"></p>
            <p class="mt-1 text-xs text-slate-400">This takes around 10-15 minutes. You can leave this page - it carries on in the background.</p>
            @php($provisionTask = $server->tasks()->where('name', 'Provision server')->latest()->first())
            @if($provisionTask)
                <a href="{{ route('servers.tasks.show', [$server, $provisionTask]) }}" class="mt-3 inline-block text-sm font-medium text-indigo-600">Watch the live output →</a>
            @endif
        </div>
    @endif

    @if($server->status === 'failed')
        <div class="card space-y-3 border-red-200 p-6">
            <h2 class="text-lg font-semibold text-red-700">Provisioning failed</h2>
            <p class="text-sm text-slate-600">{{ $server->provision_step ?? 'Something went wrong.' }}</p>
            <div class="flex gap-3">
                <form method="POST" action="{{ route('servers.provision', $server) }}">@csrf<button class="btn btn-primary">Retry</button></form>
                <a href="{{ route('servers.tasks', $server) }}" class="btn btn-secondary">View task log</a>
            </div>
        </div>
    @endif

    @if($server->status === 'disconnected')
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Deploi can't reach this server over SSH right now. Check that it's running and that port {{ $server->ssh_port }} is open.
        </div>
    @endif

    @if($server->isActive() || $server->status === 'disconnected')
        {{-- Stats --}}
        <div class="card p-5" x-data="serverStats(@js(route('servers.stats', $server)))">
            <template x-if="loading"><p class="text-sm text-slate-500">Loading server stats…</p></template>
            <template x-if="error"><p class="text-sm text-red-600" x-text="'Could not load stats: ' + error"></p></template>
            <template x-if="stats">
                <div class="grid gap-6 sm:grid-cols-4">
                    <div><p class="text-xs text-slate-500 uppercase">Load ({{ '' }}<span x-text="stats.cpus"></span> CPU)</p><p class="mt-1 font-mono text-lg" x-text="stats.load"></p></div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Memory</p>
                        <p class="mt-1 text-lg" x-text="pct(stats.mem_used, stats.mem_total) + '%'"></p>
                        <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-full rounded-full bg-indigo-500" :style="'width:' + pct(stats.mem_used, stats.mem_total) + '%'"></div></div>
                        <p class="mt-1 text-xs text-slate-500" x-text="stats.mem_used + ' / ' + stats.mem_total + ' MB'"></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 uppercase">Disk</p>
                        <p class="mt-1 text-lg" x-text="pct(stats.disk_used, stats.disk_total) + '%'"></p>
                        <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-full rounded-full bg-indigo-500" :style="'width:' + pct(stats.disk_used, stats.disk_total) + '%'"></div></div>
                        <p class="mt-1 text-xs text-slate-500" x-text="gb(stats.disk_used) + ' / ' + gb(stats.disk_total)"></p>
                    </div>
                    <div><p class="text-xs text-slate-500 uppercase">Uptime</p><p class="mt-1 text-sm" x-text="stats.uptime"></p><p class="mt-1 text-xs text-slate-500" x-text="stats.os"></p></div>
                </div>
            </template>
        </div>

        {{-- Sites --}}
        @if($server->hasNginx() || $server->imported || $sites->isNotEmpty())
            <div class="grid gap-6 lg:grid-cols-3">
                <div class="card lg:col-span-2">
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                        <span class="font-semibold">Sites</span>
                        <a href="{{ route('servers.discover', $server) }}" class="text-sm text-indigo-600">Find existing sites on this server →</a>
                    </div>
                    @if($sites->isEmpty())
                        <p class="px-5 py-8 text-center text-sm text-slate-500">
                            No sites in Deploi yet.
                            @if($server->imported)
                                <a href="{{ route('servers.discover', $server) }}" class="font-medium text-indigo-600">Import the sites already running on this server</a>.
                            @endif
                        </p>
                    @else
                        <ul class="divide-y divide-slate-100">
                            @foreach($sites as $site)
                                <li class="flex items-center justify-between px-5 py-3">
                                    <div>
                                        <a href="{{ route('sites.show', $site) }}" class="font-medium hover:text-indigo-600">{{ $site->domain }}</a>
                                        <p class="text-xs text-slate-500">
                                            {{ \App\Models\Site::PROJECT_TYPES[$site->project_type] }}
                                            @if($site->repository) · {{ $site->repository }}@endif
                                            @if($site->last_deployed_at) · deployed {{ $site->last_deployed_at->diffForHumans() }}@endif
                                        </p>
                                    </div>
                                    <div class="flex gap-2">
                                        @if($site->imported)<x-badge status="imported">Imported</x-badge>@endif
                                        @if($site->ssl_status === 'active')<x-badge status="active">SSL</x-badge>@endif
                                        <x-badge :status="$site->status" />
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                @unless($server->hasNginx())
                    <div class="card space-y-3 p-5 text-sm text-slate-600">
                        <h2 class="font-semibold text-slate-900">Adding new sites</h2>
                        <p>Deploi creates new sites as Nginx virtual hosts, and this server {{ $server->webserver === 'apache' ? 'runs Apache' : 'has no Nginx' }}. You can still import and deploy the sites that already exist here.</p>
                        <a href="{{ route('servers.discover', $server) }}" class="btn btn-secondary">Discover existing sites</a>
                    </div>
                @else
                <form method="POST" action="{{ route('servers.sites.store', $server) }}" class="card space-y-4 p-5" x-data="{ type: '{{ old('project_type', 'laravel') }}' }">
                    @csrf
                    <h2 class="font-semibold">New site</h2>
                    <div>
                        <label class="label" for="domain">Domain</label>
                        <input class="input" id="domain" name="domain" value="{{ old('domain') }}" placeholder="example.com" required>
                    </div>
                    <div>
                        <label class="label" for="aliases">Aliases <span class="font-normal text-slate-400">(optional)</span></label>
                        <input class="input" id="aliases" name="aliases" value="{{ old('aliases') }}" placeholder="www.example.com">
                    </div>
                    <div>
                        <label class="label" for="project_type">Project type</label>
                        <select class="input" id="project_type" name="project_type" x-model="type">
                            @foreach(\App\Models\Site::PROJECT_TYPES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div x-show="type !== 'nodejs'">
                        <label class="label" for="web_directory">Web directory</label>
                        <input class="input" id="web_directory" name="web_directory" :value="type === 'laravel' ? '/public' : (type === 'static' ? '/dist' : '/')">
                        <p class="hint">Relative to the site folder (<span class="code">/home/{{ $server->system_user }}/&lt;domain&gt;</span>).</p>
                    </div>
                    <div x-show="type === 'nodejs'" x-cloak>
                        <label class="label" for="app_port">App port</label>
                        <input class="input" id="app_port" type="number" name="app_port" value="{{ old('app_port', 3000) }}">
                        <p class="hint">Nginx proxies to 127.0.0.1 on this port. Run your app with a Daemon.</p>
                    </div>
                    <div x-show="['laravel', 'php', 'wordpress'].includes(type)">
                        <label class="label" for="php_version">PHP version</label>
                        <select class="input" id="php_version" name="php_version">
                            @foreach($server->installed_php_versions ?? [] as $v)
                                <option value="{{ $v }}" @selected($v === $server->php_version)>PHP {{ $server->phpRelease($v) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <input type="hidden" name="zero_downtime" value="0">
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="zero_downtime" value="1" class="mt-0.5 rounded" @checked(old('zero_downtime'))>
                        <span>Zero-downtime deployments<span class="block text-xs text-slate-500">Each deploy builds a fresh release and switches a symlink. Enables rollbacks.</span></span>
                    </label>
                    <button class="btn btn-primary w-full">Add site</button>
                </form>
                @endunless
            </div>
        @endif

        {{-- Server details --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="card space-y-4 p-5 text-sm">
                <h2 class="font-semibold">Access</h2>
                <dl class="grid grid-cols-3 gap-y-2">
                    <dt class="text-slate-500">SSH</dt>
                    <dd class="col-span-2 font-mono text-xs">ssh {{ $server->system_user }}@{{ $server->ip_address }}{{ $server->ssh_port != 22 ? ' -p '.$server->ssh_port : '' }}</dd>
                    @unless($server->imported)
                        <dt class="text-slate-500">Sudo password</dt>
                        <dd class="col-span-2" x-data="{ show: false }"><button type="button" class="text-indigo-600" x-show="!show" @click="show = true">Reveal</button><span x-show="show" x-cloak class="font-mono text-xs">{{ $server->sudo_password }}</span></dd>
                    @endunless
                    @if($server->hasDatabase() && $server->database_password)
                        <dt class="text-slate-500">Database user</dt>
                        <dd class="col-span-2 font-mono text-xs">{{ $server->system_user }}</dd>
                        <dt class="text-slate-500">Database password</dt>
                        <dd class="col-span-2" x-data="{ show: false }"><button type="button" class="text-indigo-600" x-show="!show" @click="show = true">Reveal</button><span x-show="show" x-cloak class="font-mono text-xs">{{ $server->database_password }}</span></dd>
                    @endif
                    @if($server->private_ip_address)
                        <dt class="text-slate-500">Private IP</dt>
                        <dd class="col-span-2 font-mono text-xs">{{ $server->private_ip_address }}</dd>
                    @endif
                </dl>
                <p class="text-xs text-slate-500">Add your own SSH key under the <a class="text-indigo-600" href="{{ route('servers.ssh-keys', $server) }}">SSH keys</a> tab to log in.</p>
                @if($server->deploy_public_key)
                    <div>
                        <p class="mb-1 font-medium">Server deploy key</p>
                        <p class="hint mb-2">Used to clone repositories. Added to GitHub/GitLab/Bitbucket automatically when you use a connected provider - for custom Git hosts add it as a deploy key yourself.</p>
                        <div class="relative">
                            <pre class="terminal pr-20 text-[11px] break-all">{{ $server->deploy_public_key }}</pre>
                            <button type="button" onclick="copyText(@js($server->deploy_public_key), this)" class="btn btn-secondary btn-sm absolute top-2 right-2">Copy</button>
                        </div>
                    </div>
                @endif
            </div>

            <div class="space-y-6">
                <form method="POST" action="{{ route('servers.update', $server) }}" class="card space-y-4 p-5">
                    @csrf @method('PUT')
                    <h2 class="font-semibold">Settings</h2>
                    <div><label class="label">Name</label><input class="input" name="name" value="{{ $server->name }}"></div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-2"><label class="label">IP address</label><input class="input" name="ip_address" value="{{ $server->ip_address }}"></div>
                        <div><label class="label">SSH port</label><input class="input" type="number" name="ssh_port" value="{{ $server->ssh_port }}"></div>
                    </div>
                    <button class="btn btn-secondary">Save</button>
                </form>

                @include('servers.partials.delete')
            </div>
        </div>

        <div class="card">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                <span class="font-semibold">Recent activity</span>
                <a href="{{ route('servers.tasks', $server) }}" class="text-sm text-indigo-600">All tasks →</a>
            </div>
            @include('servers.partials.task-list', ['tasks' => $tasks])
        </div>
    @else
        @include('servers.partials.delete')
    @endif
</x-layout>
