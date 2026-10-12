<x-layout :title="$site->domain">
    <x-slot:header><span class="text-sm text-slate-500">{{ $server->name }} / {{ $site->domain }}</span></x-slot:header>

    <x-site-header :site="$site" active="overview">
        @if($site->repository_status === 'installed' || $site->imported)
            <form method="POST" action="{{ route('sites.deploy', $site) }}">
                @csrf
                <button class="btn btn-primary">🚀 Deploy now</button>
            </form>
        @endif
    </x-site-header>

    @php($siteTask = \App\Models\ServerTask::where('subject_type', $site->getMorphClass())->where('subject_id', $site->id)->latest()->first())

    @if($site->status === 'installing' && $siteTask)
        <div class="card space-y-3 p-5">
            <h2 class="font-semibold">Creating site…</h2>
            <x-task-log :task="$siteTask" reload-on-finish height="h-48" />
        </div>
    @elseif($site->status === 'failed' && $siteTask)
        <div class="card space-y-3 border-red-200 p-5">
            <h2 class="font-semibold text-red-700">Something went wrong</h2>
            <x-task-log :task="$siteTask" height="h-64" />
        </div>
    @endif

    @if($site->imported)
        <div class="card space-y-3 p-5 text-sm">
            <h2 class="font-semibold">Imported site</h2>
            <p class="text-slate-600">
                This site was already running on the server. Deploi uses its existing {{ ucfirst($site->webserver()) }} config
                (<span class="code">{{ $site->meta['config_path'] ?? 'unknown' }}</span>) and files in <span class="code">{{ $site->path() }}</span>,
                and never rewrites or deletes them. Commands run as <span class="code">{{ $site->system_user }}</span>.
            </p>
            @unless($site->hasRepository())
                <p class="rounded-md bg-amber-50 p-3 text-amber-900">
                    No git checkout was found in that directory, so the default deploy script has no <span class="code">git pull</span>.
                    <a href="{{ route('sites.deploy-script', $site) }}" class="font-medium underline">Edit the deploy script</a> to add your own update steps.
                </p>
            @endunless
        </div>
    @endif

    {{-- No install in progress/done (a leftover repository name from an earlier failed attempt is fine) --}}
    @if($site->status === 'active' && $site->repository_status === null && ! $site->imported)
        {{-- Install repository --}}
        @php($selectedHost = old('repository_provider', $site->repository_provider ?? ($sourceProviders->first()?->provider ?? 'github')))
        <form method="POST" action="{{ route('sites.repository.install', $site) }}" class="card space-y-5 p-6"
              x-data="repoForm(@js($sourceProviders->map(fn ($s) => ['id' => $s->id, 'provider' => $s->provider, 'label' => $s->label])->values()), @js(route('account.source-providers.repositories', '__ID__')), @js(array_filter(['repository_provider' => $selectedHost, 'source_provider_id' => old('source_provider_id', $site->source_provider_id ? (string) $site->source_provider_id : null)], fn ($v) => $v !== null)))">
            @csrf
            <div>
                <h2 class="text-lg font-semibold">Install a repository</h2>
                <p class="text-sm text-slate-500">Deploi clones it, prepares <span class="code">.env</span>, then runs your first deployment.</p>
            </div>

            <div>
                <p class="label">Git host</p>
                <div class="grid gap-3 sm:grid-cols-4">
                    @foreach(\App\Models\Site::REPOSITORY_PROVIDERS as $value => $label)
                        {{-- Pre-checked server-side so the form never depends on JavaScript to pick a host --}}
                        <label @class([
                                'cursor-pointer rounded-lg border p-3 text-center text-sm font-medium',
                                'border-indigo-500 ring-2 ring-indigo-500/20' => $selectedHost === $value,
                                'border-slate-200' => $selectedHost !== $value,
                            ]) :class="provider === '{{ $value }}' ? 'border-indigo-500 ring-2 ring-indigo-500/20' : 'border-slate-200'">
                            <input type="radio" name="repository_provider" value="{{ $value }}" x-model="provider" class="sr-only" @checked($selectedHost === $value)>{{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div x-show="provider !== 'custom'">
                    <label class="label">Account</label>
                    <select class="input" name="source_provider_id" x-model="sourceId">
                        <option value="">Not connected (add the deploy key manually)</option>
                        <template x-for="s in sourcesForProvider" :key="s.id"><option :value="s.id" x-text="s.label"></option></template>
                    </select>
                    <p class="hint" x-show="!sourcesForProvider.length"><a class="text-indigo-600" href="{{ route('account.providers') }}#source">Connect an account</a> to add keys and webhooks automatically.</p>
                </div>
                <div :class="provider === 'custom' && 'sm:col-span-2'">
                    <label class="label" x-text="provider === 'custom' ? 'Git URL' : 'Repository'">Repository</label>
                    <input class="input" name="repository" list="repo-list" value="{{ old('repository', $site->repository) }}" :placeholder="provider === 'custom' ? 'git@git.example.com:team/app.git' : 'owner/repository'" required>
                    <datalist id="repo-list"><template x-for="r in repos" :key="r"><option :value="r"></option></template></datalist>
                </div>
                <div>
                    <label class="label">Branch</label>
                    <input class="input" name="branch" value="{{ old('branch', $site->branch ?: 'main') }}" required>
                </div>
                @if($site->isLaravel() && $databases->isNotEmpty())
                    <div>
                        <label class="label">Database <span class="font-normal text-slate-400">(fills DB_* in .env)</span></label>
                        <select class="input" name="database_id">
                            <option value="">None</option>
                            @foreach($databases as $database)<option value="{{ $database->id }}">{{ $database->name }}</option>@endforeach
                        </select>
                    </div>
                @endif
            </div>

            <input type="hidden" name="auto_deploy" value="0">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="auto_deploy" value="1" class="rounded" checked> Deploy automatically when I push to this branch</label>

            <div x-show="provider === 'custom' || !sourceId" class="rounded-md bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-medium">Add this server's key to your Git host as a (read-only) deploy key first:</p>
                <pre class="terminal mt-2 text-[11px] break-all">{{ $server->deploy_public_key ?? 'Not available - re-provision the server.' }}</pre>
            </div>

            <button class="btn btn-primary">Install repository</button>
        </form>
    @endif

    @if($site->repository_status === 'installing' || $site->repository_status === 'failed')
        @if($siteTask)
            <div class="card space-y-3 p-5">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">{{ $site->repository_status === 'failed' ? 'Repository installation failed' : 'Installing repository…' }}</h2>
                    @if($site->repository_status === 'failed')
                        <x-delete-button :action="route('sites.repository.destroy', $site)" label="Reset and try again" confirm="Remove the repository settings so you can retry?" class="btn btn-secondary" />
                    @endif
                </div>
                <x-task-log :task="$siteTask" :reload-on-finish="$site->repository_status === 'installing'" height="h-64" />
            </div>
        @endif
    @endif

    @if($site->repository_status === 'installed' || $site->imported)
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card overflow-x-auto lg:col-span-2">
                <div class="border-b border-slate-200 px-5 py-3 font-semibold">Deployments</div>
                @if($deployments->isEmpty())
                    <p class="px-5 py-6 text-sm text-slate-500">No deployments yet.</p>
                @else
                    <table class="table">
                        <thead><tr><th>Commit</th><th>Trigger</th><th>When</th><th>Status</th><th></th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach($deployments as $deployment)
                            <tr class="hover:bg-slate-50">
                                <td class="max-w-xs">
                                    <a href="{{ route('sites.deployments.show', [$site, $deployment]) }}" class="block truncate hover:text-indigo-600">
                                        @if($deployment->commit_hash)
                                            <span class="font-mono text-xs">{{ substr($deployment->commit_hash, 0, 7) }}</span> {{ $deployment->commit_message }}
                                        @else
                                            Deployment #{{ $deployment->id }}
                                        @endif
                                    </a>
                                    @if($deployment->commit_author)<p class="text-xs text-slate-500">{{ $deployment->commit_author }}</p>@endif
                                </td>
                                <td class="text-xs">{{ ucfirst($deployment->triggered_by) }}</td>
                                <td class="text-xs whitespace-nowrap text-slate-500">{{ $deployment->created_at->diffForHumans() }}</td>
                                <td><x-badge :status="$deployment->status" /></td>
                                <td class="text-right">
                                    @if($site->zero_downtime && $deployment->release && $deployment->status === 'finished' && ! $loop->first)
                                        <form method="POST" action="{{ route('sites.deployments.rollback', [$site, $deployment]) }}" onsubmit="return confirm('Roll back to this release?')">
                                            @csrf <button class="text-xs font-medium text-indigo-600">Roll back</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="space-y-6">
                @if($site->hasRepository())
                <div class="card space-y-3 p-5 text-sm">
                    <h2 class="font-semibold">Repository</h2>
                    <p><span class="text-slate-500">Repo:</span> <span class="font-mono text-xs">{{ $site->repository }}</span></p>
                    <p><span class="text-slate-500">Branch:</span> <span class="font-mono text-xs">{{ $site->branch }}</span></p>
                    <form method="POST" action="{{ route('sites.auto-deploy', $site) }}" class="flex items-center justify-between">
                        @csrf
                        <span>Auto-deploy on push</span>
                        <button @class(['relative inline-flex h-6 w-11 rounded-full transition', 'bg-indigo-600' => $site->auto_deploy, 'bg-slate-300' => ! $site->auto_deploy])>
                            <span @class(['absolute top-0.5 size-5 rounded-full bg-white shadow transition', 'left-5.5' => $site->auto_deploy, 'left-0.5' => ! $site->auto_deploy])></span>
                        </button>
                    </form>
                    @if($site->imported)
                        <x-delete-button :action="route('sites.repository.destroy', $site)" label="Disconnect repository" confirm="Stop tracking this repository in Deploi? Files on the server are not touched." />
                    @else
                        <x-delete-button :action="route('sites.repository.destroy', $site)" label="Remove repository" confirm="Remove the repository and all deployed files from the server?" />
                    @endif
                </div>
                @endif

                <div class="card space-y-2 p-5 text-sm">
                    <h2 class="font-semibold">Deploy webhook</h2>
                    <p class="text-xs text-slate-500">POST to this URL from CI, or add it as a push webhook (Deploi ignores pushes to other branches).</p>
                    <div class="flex gap-2">
                        <input class="input font-mono text-xs" readonly value="{{ $site->webhookUrl() }}">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="copyText(@js($site->webhookUrl()), this)">Copy</button>
                    </div>
                    <form method="POST" action="{{ route('sites.webhook.regenerate', $site) }}" onsubmit="return confirm('The old URL will stop working. Continue?')">
                        @csrf <button class="text-xs text-slate-500 hover:text-red-600">Regenerate URL</button>
                    </form>
                </div>
            </div>
        </div>
    @endif
</x-layout>
