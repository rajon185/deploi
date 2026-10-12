<x-layout :title="$task->name">
    <x-server-header :server="$server" active="tasks" />

    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold">{{ $task->name }}</h2>
            <p class="text-sm text-slate-500">Started {{ ($task->started_at ?? $task->created_at)->toDayDateTimeString() }} · as {{ $task->user }}{{ $task->exit_code !== null ? ' · exit code '.$task->exit_code : '' }}</p>
        </div>
        <a href="{{ route('servers.tasks', $server) }}" class="btn btn-secondary">All tasks</a>
    </div>

    <x-task-log :task="$task" />

    <details class="card p-4">
        <summary class="cursor-pointer text-sm font-medium">Script</summary>
        <pre class="terminal mt-3 max-h-[32rem]">{{ $task->script }}</pre>
    </details>
</x-layout>
