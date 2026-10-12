<x-layout title="Notifications">
    <x-slot:header><h1 class="text-lg font-semibold">Notifications</h1></x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            @if($channels->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-slate-500">No notification channels. Add a Slack or Discord incoming webhook to hear about deployments and server problems.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach($channels as $channel)
                        <li class="flex items-center justify-between px-5 py-3">
                            <div>
                                <p class="font-medium">{{ $channel->label }} <span class="text-xs text-slate-500">({{ \App\Models\NotificationChannel::TYPES[$channel->type] }})</span></p>
                                <p class="text-xs text-slate-500">{{ collect($channel->events)->map(fn ($e) => \App\Models\NotificationChannel::EVENTS[$e] ?? $e)->join(', ') }}</p>
                            </div>
                            <x-delete-button :action="route('account.notifications.destroy', $channel)" confirm="Remove this channel?" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <form method="POST" action="{{ route('account.notifications.store') }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="font-semibold">Add channel</h2>
            <div>
                <label class="label">Type</label>
                <select class="input" name="type">@foreach(\App\Models\NotificationChannel::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
            </div>
            <div><label class="label">Label</label><input class="input" name="label" value="{{ old('label') }}" placeholder="#deploys" required></div>
            <div><label class="label">Webhook URL</label><input class="input" name="url" value="{{ old('url') }}" placeholder="https://hooks.slack.com/services/…" required></div>
            <div class="space-y-1">
                <p class="label">Events</p>
                @foreach(\App\Models\NotificationChannel::EVENTS as $k => $v)
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="events[]" value="{{ $k }}" class="rounded" checked> {{ $v }}</label>
                @endforeach
            </div>
            <button class="btn btn-primary w-full">Add channel</button>
        </form>
    </div>
</x-layout>
