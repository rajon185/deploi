<x-layout :title="'Deployment #'.$deployment->id">
    <x-site-header :site="$site" active="overview" />

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h2 class="text-lg font-semibold">Deployment #{{ $deployment->id }}</h2>
                <x-badge :status="$deployment->status" />
            </div>
            <p class="text-sm text-slate-500">
                {{ ucfirst($deployment->triggered_by) }} · {{ $deployment->created_at->toDayDateTimeString() }}
                @if($deployment->commit_hash) · <span class="font-mono">{{ substr($deployment->commit_hash, 0, 7) }}</span> {{ $deployment->commit_message }}@endif
                @if($deployment->release) · release {{ $deployment->release }}@endif
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('sites.show', $site) }}" class="btn btn-secondary">Back to site</a>
            @unless($deployment->isRunning())
                <form method="POST" action="{{ route('sites.deploy', $site) }}">@csrf<button class="btn btn-primary">Deploy again</button></form>
            @endunless
        </div>
    </div>

    @if($deployment->task)
        <x-task-log :task="$deployment->task" :reload-on-finish="$deployment->isRunning()" />
    @else
        <x-empty>Waiting for a worker to pick up this deployment…</x-empty>
    @endif
</x-layout>
