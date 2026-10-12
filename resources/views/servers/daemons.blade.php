<x-layout :title="'Daemons · '.$server->name">
    <x-server-header :server="$server" active="daemons" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-x-auto lg:col-span-2">
            @if($daemons->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">No daemons. Use them for long-running processes like Node apps, Laravel Horizon or Reverb - Supervisor keeps them alive.</p>
            @else
                <table class="table">
                    <thead><tr><th>Command</th><th>Directory</th><th>User</th><th>Procs</th><th>Status</th><th></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach($daemons as $daemon)
                        <tr>
                            <td class="max-w-xs truncate font-mono text-xs" title="{{ $daemon->command }}">{{ $daemon->command }}</td>
                            <td class="font-mono text-xs">{{ $daemon->directory ?: '~' }}</td>
                            <td class="text-xs">{{ $daemon->user }}</td>
                            <td>{{ $daemon->processes }}</td>
                            <td><x-badge :status="$daemon->status" /></td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                <form method="POST" action="{{ route('servers.daemons.restart', [$server, $daemon]) }}" class="inline">@csrf<button class="text-sm font-medium text-indigo-600">Restart</button></form>
                                <x-delete-button :action="route('servers.daemons.destroy', [$server, $daemon])" confirm="Stop and remove this daemon?" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">Logs: <span class="code">/var/log/deploi/daemon-&lt;id&gt;.log</span></p>
            @endif
        </div>

        <form method="POST" action="{{ route('servers.daemons.store', $server) }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="font-semibold">New daemon</h2>
            <div><label class="label">Command</label><input class="input font-mono" name="command" value="{{ old('command') }}" placeholder="php8.3 artisan horizon" required></div>
            <div><label class="label">Directory</label><input class="input font-mono" name="directory" value="{{ old('directory') }}" placeholder="/home/{{ $server->system_user }}/example.com"></div>
            <div><label class="label">User</label><select class="input" name="user"><option>{{ $server->system_user }}</option><option>root</option></select></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Processes</label><input class="input" type="number" name="processes" value="{{ old('processes', 1) }}"></div>
                <div><label class="label">Stop wait (s)</label><input class="input" type="number" name="stop_wait_seconds" value="{{ old('stop_wait_seconds', 10) }}"></div>
            </div>
            <button class="btn btn-primary w-full">Add daemon</button>
        </form>
    </div>
</x-layout>
