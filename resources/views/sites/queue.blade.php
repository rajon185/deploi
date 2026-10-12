<x-layout :title="'Queue · '.$site->domain">
    <x-site-header :site="$site" active="queue" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-x-auto lg:col-span-2">
            @if($workers->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">No queue workers. Workers run <span class="code">php artisan queue:work</span> under Supervisor.</p>
            @else
                <table class="table">
                    <thead><tr><th>Connection</th><th>Queue</th><th>Procs</th><th>Timeout</th><th>Status</th><th></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach($workers as $worker)
                        <tr>
                            <td>{{ $worker->connection }}</td>
                            <td class="font-mono text-xs">{{ $worker->queue }}</td>
                            <td>{{ $worker->processes }}</td>
                            <td>{{ $worker->timeout }}s</td>
                            <td><x-badge :status="$worker->status" /></td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                <form method="POST" action="{{ route('sites.queue.restart', [$site, $worker]) }}" class="inline">@csrf<button class="text-sm font-medium text-indigo-600">Restart</button></form>
                                <x-delete-button :action="route('sites.queue.destroy', [$site, $worker])" confirm="Stop and remove this worker?" />
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <form method="POST" action="{{ route('sites.queue.store', $site) }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="font-semibold">New worker</h2>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Connection</label><input class="input" name="connection" value="redis"></div>
                <div><label class="label">Queue</label><input class="input" name="queue" value="default"></div>
                <div><label class="label">Processes</label><input class="input" type="number" name="processes" value="1"></div>
                <div><label class="label">Timeout (s)</label><input class="input" type="number" name="timeout" value="60"></div>
                <div><label class="label">Sleep (s)</label><input class="input" type="number" name="sleep" value="3"></div>
                <div><label class="label">Tries</label><input class="input" type="number" name="tries" value="1"></div>
                <div class="col-span-2"><label class="label">Memory limit (MB)</label><input class="input" type="number" name="memory" value="256"></div>
            </div>
            <button class="btn btn-primary w-full">Start worker</button>
        </form>
    </div>
</x-layout>
