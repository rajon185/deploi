<x-layout title="API tokens">
    <x-slot:header><h1 class="text-lg font-semibold">API tokens</h1></x-slot:header>

    @if(session('token'))
        <div class="card space-y-2 border-emerald-200 p-5">
            <p class="font-medium text-emerald-800">Copy your new token now - it won't be shown again.</p>
            <div class="flex gap-2"><input class="input font-mono text-xs" readonly value="{{ session('token') }}"><button type="button" class="btn btn-secondary" onclick="copyText(@js(session('token')), this)">Copy</button></div>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                @if($tokens->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-slate-500">No API tokens.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach($tokens as $token)
                            <li class="flex items-center justify-between px-5 py-3">
                                <div><p class="font-medium">{{ $token->name }}</p><p class="text-xs text-slate-500">Created {{ $token->created_at->diffForHumans() }} · last used {{ $token->last_used_at?->diffForHumans() ?? 'never' }}</p></div>
                                <x-delete-button :action="route('account.api-tokens.destroy', $token->id)" label="Revoke" confirm="Revoke this token?" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="card space-y-3 p-5 text-sm">
                <h2 class="font-semibold">Using the API</h2>
                <pre class="terminal">curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" {{ url('/api/servers') }}

GET  /api/servers               GET  /api/sites
GET  /api/servers/{id}          GET  /api/sites/{id}
POST /api/sites/{id}/deploy     GET  /api/sites/{id}/deployments
GET  /api/sites/{id}/deployments/{deploymentId}   (includes log output)</pre>
            </div>
        </div>

        <form method="POST" action="{{ route('account.api-tokens.store') }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="font-semibold">Create token</h2>
            <div><label class="label">Name</label><input class="input" name="name" placeholder="GitHub Actions" required></div>
            <button class="btn btn-primary w-full">Create token</button>
        </form>
    </div>
</x-layout>
