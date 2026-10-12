@if($tasks->isEmpty())
    <p class="px-5 py-6 text-sm text-slate-500">Nothing has run on this server yet.</p>
@else
    <ul class="divide-y divide-slate-100">
        @foreach($tasks as $task)
            <li>
                <a href="{{ route('servers.tasks.show', [$task->server_id, $task]) }}" class="flex items-center justify-between gap-4 px-5 py-3 hover:bg-slate-50">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $task->name }}</p>
                        <p class="text-xs text-slate-500">{{ $task->created_at->diffForHumans() }}{{ $task->duration() ? ' · '.$task->duration() : '' }} · as {{ $task->user }}</p>
                    </div>
                    <x-badge :status="$task->status" />
                </a>
            </li>
        @endforeach
    </ul>
@endif
