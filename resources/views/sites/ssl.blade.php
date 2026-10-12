<x-layout :title="'SSL · '.$site->domain">
    <x-site-header :site="$site" active="ssl" />

    @if($site->imported && ! empty($site->meta['ssl_certificate']))
        <div class="card space-y-1 p-5 text-sm">
            <h2 class="font-semibold">Existing certificate</h2>
            <p class="text-slate-600">Configured in the site's own {{ ucfirst($site->webserver()) }} config:</p>
            <p class="font-mono text-xs">{{ $site->meta['ssl_certificate'] }}</p>
            @if(! empty($site->meta['ssl_expires']))
                @php($expires = \Illuminate\Support\Carbon::parse($site->meta['ssl_expires']))
                <p @class(['text-red-600' => $expires->isPast() || $expires->diffInDays() < 14, 'text-slate-500' => ! ($expires->isPast() || $expires->diffInDays() < 14)])>{{ $expires->isPast() ? 'Expired' : 'Expires' }} {{ $expires->toFormattedDateString() }} (as of the last scan)</p>
            @endif
        </div>
    @endif

    <div class="card overflow-x-auto">
        <div class="border-b border-slate-200 px-5 py-3 font-semibold">Certificates issued by Deploi</div>
        @if($certificates->isEmpty())
            <p class="px-5 py-6 text-sm text-slate-500">This site is served over plain HTTP.</p>
        @else
            <table class="table">
                <thead><tr><th>Type</th><th>Domains</th><th>Expires</th><th>Status</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach($certificates as $cert)
                    <tr>
                        <td>{{ $cert->type === 'letsencrypt' ? "Let's Encrypt" : 'Custom' }} @if($cert->active)<x-badge status="active">In use</x-badge>@endif</td>
                        <td class="text-xs">{{ implode(', ', $cert->domains) }}</td>
                        <td class="text-xs">{{ $cert->expires_at?->toFormattedDateString() ?? '—' }}{{ $cert->type === 'letsencrypt' && $cert->status === 'active' ? ' (auto-renews)' : '' }}</td>
                        <td><x-badge :status="$cert->status" /></td>
                        <td class="text-right">
                            @php($certTask = \App\Models\ServerTask::where('subject_type', $cert->getMorphClass())->where('subject_id', $cert->id)->latest()->first())
                            @if($certTask)<a href="{{ route('servers.tasks.show', [$server, $certTask]) }}" class="mr-3 text-sm text-slate-500 hover:text-indigo-600">Log</a>@endif
                            <x-delete-button :action="route('sites.ssl.destroy', [$site, $cert])" confirm="Remove this certificate?" />
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ route('sites.ssl.letsencrypt', $site) }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="font-semibold">Let's Encrypt (free)</h2>
            <p class="text-sm text-slate-600">Every domain below must already have an A/AAAA record pointing to <span class="code">{{ $server->ip_address }}</span>.</p>
            @if($site->imported)
                <p class="text-sm text-slate-600">For imported sites, certbot's {{ ucfirst($site->webserver()) }} plugin adds the certificate (and an HTTP→HTTPS redirect) to the existing config in place. It validates the config before reloading.</p>
            @endif
            <div class="space-y-1">
                @foreach($site->allDomains() as $domain)
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="domains[]" value="{{ $domain }}" class="rounded" checked> {{ $domain }}</label>
                @endforeach
            </div>
            <button class="btn btn-primary">Obtain certificate</button>
        </form>

        @if($site->managesWebserverConfig())
        <form method="POST" action="{{ route('sites.ssl.custom', $site) }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="font-semibold">Install your own certificate</h2>
            <div><label class="label">Certificate (with intermediate chain)</label><textarea class="input font-mono text-xs" rows="5" name="certificate" placeholder="-----BEGIN CERTIFICATE-----"></textarea></div>
            <div><label class="label">Private key</label><textarea class="input font-mono text-xs" rows="5" name="private_key" placeholder="-----BEGIN PRIVATE KEY-----"></textarea></div>
            <button class="btn btn-secondary">Install certificate</button>
        </form>
        @endif
    </div>
</x-layout>
