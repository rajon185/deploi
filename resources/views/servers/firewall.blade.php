<x-layout :title="'Firewall · '.$server->name">
    <x-server-header :server="$server" active="firewall" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-x-auto lg:col-span-2">
            <table class="table">
                <thead><tr><th>Name</th><th>Port</th><th>Protocol</th><th>From</th><th>Rule</th><th>Status</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach($rules as $rule)
                    <tr>
                        <td>{{ $rule->name }} @if($rule->system)<span class="text-xs text-slate-400">(default)</span>@endif</td>
                        <td class="font-mono text-xs">{{ $rule->port }}</td>
                        <td class="text-xs uppercase">{{ $rule->protocol }}</td>
                        <td class="font-mono text-xs">{{ $rule->from ?: 'Anywhere' }}</td>
                        <td><span @class(['text-xs font-semibold uppercase', 'text-emerald-600' => $rule->rule_type === 'allow', 'text-red-600' => $rule->rule_type === 'deny'])>{{ $rule->rule_type }}</span></td>
                        <td><x-badge :status="$rule->status" /></td>
                        <td class="text-right">
                            @unless($rule->system && $rule->port === '22')
                                <x-delete-button :action="route('servers.firewall.destroy', [$server, $rule])" confirm="Remove this firewall rule?" />
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">Everything not allowed here is blocked by UFW. Your cloud provider's own firewall (if any) also applies.</p>
        </div>

        <form method="POST" action="{{ route('servers.firewall.store', $server) }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="font-semibold">New rule</h2>
            <div><label class="label">Name</label><input class="input" name="name" value="{{ old('name') }}" placeholder="MySQL from office" required></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Port</label><input class="input" name="port" value="{{ old('port') }}" placeholder="3306" required></div>
                <div><label class="label">Protocol</label><select class="input" name="protocol"><option value="tcp">TCP</option><option value="udp">UDP</option><option value="any">Any</option></select></div>
            </div>
            <div><label class="label">From IP / CIDR</label><input class="input" name="from" value="{{ old('from') }}" placeholder="Anywhere"></div>
            <div><label class="label">Action</label><select class="input" name="rule_type"><option value="allow">Allow</option><option value="deny">Deny</option></select></div>
            <button class="btn btn-primary w-full">Add rule</button>
        </form>
    </div>
</x-layout>
