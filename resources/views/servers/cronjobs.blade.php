<x-layout :title="'Cron jobs · '.$server->name">
    <x-server-header :server="$server" active="cronjobs" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-x-auto lg:col-span-2">
            @if($cronjobs->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">No cron jobs. Laravel sites can add the scheduler with one click from their Settings tab.</p>
            @else
                <table class="table">
                    <thead><tr><th>Command</th><th>Schedule</th><th>User</th><th>Status</th><th></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach($cronjobs as $cron)
                        <tr>
                            <td class="max-w-sm truncate font-mono text-xs" title="{{ $cron->command }}">{{ $cron->command }}</td>
                            <td class="text-xs"><span class="code">{{ $cron->frequency }}</span><span class="ml-1 text-slate-500">{{ \App\Models\Cronjob::PRESETS[$cron->frequency] ?? '' }}</span></td>
                            <td class="text-xs">{{ $cron->user }}</td>
                            <td><x-badge :status="$cron->status" /></td>
                            <td class="text-right"><x-delete-button :action="route('servers.cronjobs.destroy', [$server, $cron])" confirm="Remove this cron job?" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <form method="POST" action="{{ route('servers.cronjobs.store', $server) }}" class="card space-y-4 p-5" x-data="{ freq: '{{ old('frequency', '* * * * *') }}' }">
            @csrf
            <h2 class="font-semibold">New cron job</h2>
            <div>
                <label class="label">Command</label>
                <input class="input font-mono" name="command" value="{{ old('command') }}" placeholder="php8.3 /home/deploi/example.com/artisan schedule:run" required>
                <p class="hint">Escape <span class="code">%</span> as <span class="code">\%</span> (cron treats it as a newline).</p>
            </div>
            <div>
                <label class="label">User</label>
                <select class="input" name="user"><option>{{ $server->system_user }}</option><option>root</option></select>
            </div>
            <div>
                <label class="label">Frequency</label>
                <select class="input" name="frequency" x-model="freq">
                    @foreach(\App\Models\Cronjob::PRESETS as $expr => $label)
                        <option value="{{ $expr }}">{{ $label }} ({{ $expr }})</option>
                    @endforeach
                    <option value="custom">Custom…</option>
                </select>
            </div>
            <div x-show="freq === 'custom'" x-cloak>
                <label class="label">Cron expression</label>
                <input class="input font-mono" name="custom_frequency" value="{{ old('custom_frequency') }}" placeholder="30 2 * * 1-5">
            </div>
            <button class="btn btn-primary w-full">Add cron job</button>
        </form>
    </div>
</x-layout>
