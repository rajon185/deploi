<x-layout :title="'Databases · '.$server->name">
    <x-server-header :server="$server" active="databases" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card overflow-x-auto">
                <div class="border-b border-slate-200 px-5 py-3 font-semibold">Databases</div>
                @if($databases->isEmpty())
                    <p class="px-5 py-6 text-sm text-slate-500">No databases yet.</p>
                @else
                    <table class="table">
                        <thead><tr><th>Name</th><th>Engine</th><th>Users</th><th>Backups</th><th>Status</th><th></th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach($databases as $database)
                            <tr>
                                <td class="font-mono text-xs">{{ $database->name }}</td>
                                <td class="text-xs">{{ $engines[$database->engine] ?? $database->engine }}</td>
                                <td class="text-xs">{{ $database->users->pluck('username')->join(', ') ?: '—' }}</td>
                                <td class="text-xs">
                                    @forelse($database->backups as $backup)
                                        <div class="flex items-center gap-2">
                                            <span>{{ $backup->disk->label }} · <span class="code">{{ $backup->frequency }}</span> · keep {{ $backup->keep }}</span>
                                            <form method="POST" action="{{ route('servers.backups.run', [$server, $database, $backup]) }}">@csrf<button class="text-indigo-600">Run now</button></form>
                                            <x-delete-button :action="route('servers.backups.destroy', [$server, $database, $backup])" label="×" confirm="Remove this backup schedule?" />
                                        </div>
                                    @empty
                                        —
                                    @endforelse
                                </td>
                                <td><x-badge :status="$database->status" /></td>
                                <td class="text-right"><x-delete-button :action="route('servers.databases.destroy', [$server, $database])" label="Drop" :confirm="'Drop database '.$database->name.'? All data will be lost.'" /></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="card overflow-x-auto">
                <div class="border-b border-slate-200 px-5 py-3 font-semibold">Database users</div>
                @if($users->isEmpty())
                    <p class="px-5 py-6 text-sm text-slate-500">No extra users. The <span class="code">{{ $server->system_user }}</span> user (password on the Overview tab) has access to everything.</p>
                @else
                    <table class="table">
                        <thead><tr><th>User</th><th>Engine</th><th>Password</th><th>Access to</th><th>Status</th><th></th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach($users as $user)
                            <tr>
                                <td class="font-mono text-xs">{{ $user->username }}</td>
                                <td class="text-xs">{{ $engines[$user->engine] ?? $user->engine }}</td>
                                <td x-data="reveal(@js(route('servers.database-users.password', [$server, $user])))">
                                    <button type="button" class="text-xs text-indigo-600" x-show="!value" @click="show()">Reveal</button>
                                    <span class="font-mono text-xs" x-text="value" x-cloak></span>
                                </td>
                                <td class="text-xs">{{ $user->databases->pluck('name')->join(', ') ?: 'none' }}</td>
                                <td><x-badge :status="$user->status" /></td>
                                <td class="text-right whitespace-nowrap">
                                    <x-delete-button :action="route('servers.database-users.destroy', [$server, $user])" :confirm="'Delete user '.$user->username.'?'" />
                                </td>
                            </tr>
                            <tr>
                                <td colspan="6" class="!p-0">
                                    <details class="px-4 py-2 text-sm">
                                        <summary class="cursor-pointer text-xs text-slate-500">Change password / access for {{ $user->username }}</summary>
                                        <form method="POST" action="{{ route('servers.database-users.update', [$server, $user]) }}" class="mt-3 space-y-3 pb-2">
                                            @csrf @method('PUT')
                                            <input class="input" name="password" placeholder="New password (leave blank to keep)">
                                            <div class="flex flex-wrap gap-4">
                                                @foreach($databases->where('engine', $user->engine) as $database)
                                                    <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="databases[]" value="{{ $database->id }}" class="rounded" @checked($user->databases->contains($database))> {{ $database->name }}</label>
                                                @endforeach
                                            </div>
                                            <button class="btn btn-secondary btn-sm">Update user</button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <form method="POST" action="{{ route('servers.databases.store', $server) }}" class="card space-y-4 p-5">
                @csrf
                <h2 class="font-semibold">New database</h2>
                <div x-data="{ engine: @js(old('engine', $server->database_type ?? 'mysql')), installed: @js($server->databaseEngines()) }">
                    <label class="label" for="engine">Engine</label>
                    <select class="input" id="engine" name="engine" x-model="engine">
                        @foreach($engines as $value => $label)
                            <option value="{{ $value }}" @disabled($server->conflictingDatabaseEngine($value))>
                                {{ $label }}{{ $server->hasDatabaseEngine($value) ? '' : ($server->conflictingDatabaseEngine($value) ? ' (conflicts with '.$engines[$server->conflictingDatabaseEngine($value)].')' : ' (will be installed)') }}
                            </option>
                        @endforeach
                    </select>
                    <p class="hint" x-show="!installed.includes(engine)" x-cloak>This engine isn't on the server yet. Deploi will install it first, which takes a few minutes.</p>
                    @error('engine')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div><label class="label">Name</label><input class="input" name="name" value="{{ old('name') }}" placeholder="my_app" required></div>
                <div><label class="label">User <span class="font-normal text-slate-400">(optional)</span></label><input class="input" name="username" value="{{ old('username') }}" placeholder="my_app"></div>
                <div><label class="label">Password <span class="font-normal text-slate-400">(optional)</span></label><input class="input" name="password" placeholder="Generated if blank"></div>
                <button class="btn btn-primary w-full">Create database</button>
            </form>

            <form method="POST" action="{{ route('servers.database-users.store', $server) }}" class="card space-y-4 p-5">
                @csrf
                <h2 class="font-semibold">New database user</h2>
                @if(! $server->hasDatabase())
                    <p class="text-sm text-slate-500">Create a database first - that installs a database engine.</p>
                @else
                    <div x-data="{ engine: @js($server->database_type ?? $server->databaseEngines()[0]) }" class="space-y-4">
                        @if(count($server->databaseEngines()) > 1)
                            <div><label class="label">Engine</label>
                                <select class="input" name="engine" x-model="engine">@foreach($server->databaseEngines() as $engine)<option value="{{ $engine }}">{{ $engines[$engine] ?? $engine }}</option>@endforeach</select>
                            </div>
                        @else
                            <input type="hidden" name="engine" value="{{ $server->databaseEngines()[0] }}">
                        @endif
                        <div><label class="label">Username</label><input class="input" name="username" required></div>
                        <div><label class="label">Password</label><input class="input" name="password" placeholder="Generated if blank"></div>
                        @if($databases->isNotEmpty())
                            <div class="space-y-1">
                                <p class="label">Access to</p>
                                @foreach($databases as $database)
                                    <label class="flex items-center gap-2 text-sm" x-show="engine === @js($database->engine)"><input type="checkbox" name="databases[]" value="{{ $database->id }}" class="rounded" :disabled="engine !== @js($database->engine)"> {{ $database->name }}</label>
                                @endforeach
                            </div>
                        @endif
                        <button class="btn btn-secondary w-full">Create user</button>
                    </div>
                @endif
            </form>

            @if($databases->isNotEmpty())
                <form method="POST" x-data="{ db: '{{ $databases->first()->id }}' }" :action="@js(route('servers.backups.store', [$server, '__DB__'])).replace('__DB__', db)" class="card space-y-4 p-5">
                    @csrf
                    <h2 class="font-semibold">Scheduled backups</h2>
                    @if($disks->isEmpty())
                        <p class="text-sm text-slate-500">Add S3-compatible storage (AWS S3, DigitalOcean Spaces, Backblaze B2…) under <a href="{{ route('account.backup-disks') }}" class="text-indigo-600">Backup storage</a> first.</p>
                    @else
                        <div><label class="label">Database</label>
                            <select class="input" x-model="db">@foreach($databases as $database)<option value="{{ $database->id }}">{{ $database->name }}</option>@endforeach</select>
                        </div>
                        <div><label class="label">Storage</label>
                            <select class="input" name="backup_disk_id">@foreach($disks as $disk)<option value="{{ $disk->id }}">{{ $disk->label }}</option>@endforeach</select>
                        </div>
                        <div><label class="label">Schedule (cron)</label><input class="input font-mono" name="frequency" value="0 3 * * *"><p class="hint">Default: daily at 03:00 UTC.</p></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="label">Keep</label><input class="input" type="number" name="keep" value="7"></div>
                            <div><label class="label">Folder</label><input class="input" name="path" value="deploi-backups"></div>
                        </div>
                        <button class="btn btn-secondary w-full">Schedule backups</button>
                    @endif
                </form>
            @endif
        </div>
    </div>
</x-layout>
