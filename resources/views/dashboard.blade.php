<x-layout title="Dashboard">
    <x-slot:header><h1 class="text-lg font-semibold">Dashboard</h1></x-slot:header>
    <x-slot:actions>
        <a href="{{ route('servers.create', ['import' => 1]) }}" class="btn btn-secondary">Import existing server</a>
        <a href="{{ route('servers.create') }}" class="btn btn-primary">New server</a>
    </x-slot:actions>

    @if($servers->isEmpty())
        <div class="card p-8">
            <h2 class="text-xl font-semibold">Welcome to Deploi 👋</h2>
            <p class="mt-2 text-slate-600">Get your first server online in three steps:</p>
            <ol class="mt-4 space-y-3 text-sm">
                <li class="flex gap-3">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-indigo-100 font-semibold text-indigo-700">1</span>
                    <span><a href="{{ route('account.providers') }}" class="font-medium text-indigo-600">Connect DigitalOcean</a> (or Hetzner, Vultr, Linode) with an API token - or skip this and use any Ubuntu server you can SSH into.</span>
                </li>
                <li class="flex gap-3">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-indigo-100 font-semibold text-indigo-700">2</span>
                    <span><a href="{{ route('account.providers') }}#source" class="font-medium text-indigo-600">Connect GitHub, GitLab or Bitbucket</a> so servers can pull your code.</span>
                </li>
                <li class="flex gap-3">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-indigo-100 font-semibold text-indigo-700">3</span>
                    <span><a href="{{ route('servers.create') }}" class="font-medium text-indigo-600">Create a server</a>, add a site and deploy.</span>
                </li>
                <li class="flex gap-3">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-slate-100 font-semibold text-slate-600">or</span>
                    <span>Already have servers with websites? <a href="{{ route('servers.create', ['import' => 1]) }}" class="font-medium text-indigo-600">Import an existing server</a> - Deploi scans it and lists everything that's running, without changing anything.</span>
                </li>
            </ol>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="card p-5"><p class="text-sm text-slate-500">Servers</p><p class="mt-1 text-3xl font-semibold">{{ $servers->count() }}</p></div>
            <div class="card p-5"><p class="text-sm text-slate-500">Sites</p><p class="mt-1 text-3xl font-semibold">{{ $servers->sum('sites_count') }}</p></div>
            <div class="card p-5"><p class="text-sm text-slate-500">Servers needing attention</p><p class="mt-1 text-3xl font-semibold {{ $servers->whereIn('status', ['failed', 'disconnected'])->count() ? 'text-red-600' : '' }}">{{ $servers->whereIn('status', ['failed', 'disconnected'])->count() }}</p></div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="card">
                <div class="border-b border-slate-200 px-5 py-3 font-semibold">Servers</div>
                <ul class="divide-y divide-slate-100">
                    @foreach($servers as $server)
                        <li class="flex items-center justify-between px-5 py-3">
                            <div>
                                <a href="{{ route('servers.show', $server) }}" class="font-medium hover:text-indigo-600">{{ $server->name }}</a>
                                <p class="text-xs text-slate-500">{{ $server->ip_address ?? '—' }} · {{ $server->sites_count }} {{ Str::plural('site', $server->sites_count) }}</p>
                            </div>
                            <x-badge :status="$server->status" />
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="card">
                <div class="border-b border-slate-200 px-5 py-3 font-semibold">Recent deployments</div>
                @if($deployments->isEmpty())
                    <p class="px-5 py-6 text-sm text-slate-500">No deployments yet.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach($deployments as $deployment)
                            <li class="flex items-center justify-between gap-4 px-5 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('sites.deployments.show', [$deployment->site, $deployment]) }}" class="font-medium hover:text-indigo-600">{{ $deployment->site->domain }}</a>
                                    <p class="truncate text-xs text-slate-500">
                                        @if($deployment->commit_hash)<span class="font-mono">{{ substr($deployment->commit_hash, 0, 7) }}</span> {{ $deployment->commit_message }} · @endif
                                        {{ $deployment->created_at->diffForHumans() }}
                                    </p>
                                </div>
                                <x-badge :status="$deployment->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</x-layout>
