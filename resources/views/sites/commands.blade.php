<x-layout :title="'Commands · '.$site->domain">
    <x-site-header :site="$site" active="commands" />

    <form method="POST" action="{{ route('sites.commands.store', $site) }}" class="card space-y-4 p-5"
          x-data="{ command: @js(old('command', '')) }">
        @csrf
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold">Run a command</h2>
            <p class="text-xs text-slate-500">
                Runs in <span class="code">{{ $site->currentPath() }}</span> as <span class="code">{{ $site->system_user }}</span>
                @if($site->usesPhp()) · <span class="code">php</span> / <span class="code">composer</span> use PHP {{ $site->phpVersion() }}@endif
            </p>
        </div>

        <textarea name="command" x-model="command" rows="2" spellcheck="false" required
                  class="input font-mono text-sm" placeholder="{{ $site->isLaravel() ? 'php artisan migrate --force' : 'ls -la' }}"
                  @keydown.ctrl.enter="$el.form.requestSubmit()" @keydown.meta.enter="$el.form.requestSubmit()"></textarea>

        <div class="flex flex-wrap gap-2">
            @foreach($presets as $preset => $label)
                <button type="button" class="rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-600 hover:border-indigo-300 hover:text-indigo-700"
                        title="{{ $preset }}" @click="command = @js($preset)">{{ $label }}</button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button class="btn btn-primary">Run command</button>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                Stop after
                <select name="timeout" class="input w-auto py-1">
                    @foreach($timeouts as $seconds => $label)
                        <option value="{{ $seconds }}" @selected($seconds === 300)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <span class="text-xs text-slate-400">Ctrl+Enter to run. Interactive commands (that wait for input) will hang until the timeout.</span>
        </div>
    </form>

    @if($selected?->task)
        <div class="card space-y-3 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="min-w-0 truncate font-mono text-sm" title="{{ $selected->command }}">$ {{ $selected->command }}</p>
                <span class="text-xs text-slate-500">{{ $selected->created_at->diffForHumans() }}</span>
            </div>
            <x-task-log :task="$selected->task" height="h-80" />
        </div>
    @endif

    <div class="card overflow-x-auto">
        <div class="border-b border-slate-200 px-5 py-3 font-semibold">History</div>
        @if($commands->isEmpty())
            <p class="px-5 py-6 text-sm text-slate-500">No commands run on this site yet.</p>
        @else
            <table class="table">
                <thead><tr><th>Command</th><th>By</th><th>When</th><th>Status</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach($commands as $command)
                    <tr @class(['bg-indigo-50/50' => $selected?->id === $command->id])>
                        <td class="max-w-md">
                            <a href="{{ route('sites.commands', [$site, 'command' => $command->id]) }}" class="block truncate font-mono text-xs hover:text-indigo-600" title="{{ $command->command }}">{{ $command->command }}</a>
                        </td>
                        <td class="text-xs">{{ $command->user?->name ?? '—' }}</td>
                        <td class="text-xs whitespace-nowrap text-slate-500">{{ $command->created_at->diffForHumans() }}{{ $command->task?->duration() ? ' · '.$command->task->duration() : '' }}</td>
                        <td><x-badge :status="$command->task?->status ?? 'pending'" /></td>
                        <td class="text-right whitespace-nowrap">
                            <form method="POST" action="{{ route('sites.commands.store', $site) }}" class="inline">
                                @csrf
                                <input type="hidden" name="command" value="{{ $command->command }}">
                                <button class="text-sm font-medium text-indigo-600 hover:text-indigo-500">Run again</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    {{ $commands->withQueryString()->links() }}
</x-layout>
