@props(['task', 'reloadOnFinish' => false, 'height' => 'h-[32rem]'])
<div x-data="taskLog(@js(route('servers.tasks.output', [$task->server_id, $task])), @js([
        'output' => $task->output ?? '',
        'status' => $task->status,
        'running' => $task->isRunning(),
        'duration' => $task->duration(),
    ]))" @if($reloadOnFinish) data-reload-on-finish @endif class="space-y-2">
    <div class="flex items-center justify-between text-xs text-slate-500">
        <div class="flex items-center gap-2">
            <span x-show="running" class="inline-flex items-center gap-1.5 font-medium text-amber-600"><span class="size-1.5 animate-pulse rounded-full bg-current"></span>Running</span>
            <span x-show="!running && status === 'finished'" x-cloak class="font-medium text-emerald-600">✔ Finished</span>
            <span x-show="!running && status !== 'finished'" x-cloak class="font-medium text-red-600" x-text="'✘ ' + status"></span>
            <span x-show="duration" x-text="'· ' + duration"></span>
        </div>
        <label class="flex items-center gap-1.5"><input type="checkbox" x-model="follow" class="rounded"> Follow output</label>
    </div>
    <pre x-ref="log" class="terminal {{ $height }}" x-text="output.replace(/##DEPLOI_[A-Z_]+:[^#\n]*##\n?/g, '') || (running ? 'Waiting for output…' : '(no output)')"></pre>
</div>
