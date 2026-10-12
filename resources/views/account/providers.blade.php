<x-layout title="Providers">
    <x-slot:header><h1 class="text-lg font-semibold">Providers</h1></x-slot:header>

    {{-- Server providers --}}
    <section class="space-y-4">
        <div>
            <h2 class="text-lg font-semibold">Server providers</h2>
            <p class="text-sm text-slate-500">Deploi uses these API tokens to create, reboot and destroy servers. Any other host works too - just add a "Custom server".</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card lg:col-span-2">
                @if($serverProviders->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-slate-500">No server providers connected.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach($serverProviders as $provider)
                            <li class="flex items-center justify-between px-5 py-3">
                                <div>
                                    <p class="font-medium">{{ $provider->label }}</p>
                                    <p class="text-xs text-slate-500">{{ \App\Services\Providers\ProviderManager::name($provider->provider) }} · {{ $provider->servers_count }} {{ Str::plural('server', $provider->servers_count) }}</p>
                                </div>
                                <x-delete-button :action="route('account.server-providers.destroy', $provider)" label="Disconnect" confirm="Disconnect this provider? Existing servers are not affected." />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <form method="POST" action="{{ route('account.server-providers.store') }}" class="card space-y-4 p-5" x-data="{ p: '{{ old('provider', 'digitalocean') }}', help: @js(collect(\App\Services\Providers\ProviderManager::PROVIDERS)->map(fn ($p) => $p['help'])) }">
                @csrf
                <h3 class="font-semibold">Connect a provider</h3>
                <div>
                    <label class="label">Provider</label>
                    <select class="input" name="provider" x-model="p">
                        @foreach(\App\Services\Providers\ProviderManager::PROVIDERS as $key => $meta)<option value="{{ $key }}">{{ $meta['name'] }}</option>@endforeach
                    </select>
                </div>
                <div><label class="label">Label</label><input class="input" name="label" value="{{ old('label', 'Personal') }}" required></div>
                <div>
                    <label class="label">API token</label>
                    <input class="input font-mono" type="password" name="token" required autocomplete="off">
                    <p class="hint" x-text="help[p]"></p>
                </div>
                <button class="btn btn-primary w-full">Connect</button>
            </form>
        </div>
    </section>

    {{-- Source control --}}
    <section class="space-y-4" id="source">
        <div>
            <h2 class="text-lg font-semibold">Source control</h2>
            <p class="text-sm text-slate-500">Lets Deploi register each server's deploy key on your account, list your repositories and create push webhooks for auto-deploy.</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card lg:col-span-2">
                @if($sourceProviders->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-slate-500">No source control accounts connected.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach($sourceProviders as $provider)
                            <li class="flex items-center justify-between px-5 py-3">
                                <div>
                                    <p class="font-medium">{{ $provider->label }}</p>
                                    <p class="text-xs text-slate-500">{{ \App\Services\SourceControl\SourceControlManager::PROVIDERS[$provider->provider]['name'] }} · {{ $provider->username }}{{ $provider->url ? ' · '.$provider->url : '' }}</p>
                                </div>
                                <x-delete-button :action="route('account.source-providers.destroy', $provider)" label="Disconnect" confirm="Disconnect this account?" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <form method="POST" action="{{ route('account.source-providers.store') }}" class="card space-y-4 p-5" x-data="{ p: '{{ old('provider', 'github') }}', help: @js(collect(\App\Services\SourceControl\SourceControlManager::PROVIDERS)->map(fn ($p) => $p['help'])) }">
                @csrf
                <h3 class="font-semibold">Connect an account</h3>
                <div>
                    <label class="label">Provider</label>
                    <select class="input" name="provider" x-model="p">
                        @foreach(\App\Services\SourceControl\SourceControlManager::PROVIDERS as $key => $meta)<option value="{{ $key }}">{{ $meta['name'] }}</option>@endforeach
                    </select>
                </div>
                <div><label class="label">Label</label><input class="input" name="label" value="{{ old('label', 'Personal') }}" required></div>
                <div x-show="p === 'bitbucket'" x-cloak><label class="label">Username</label><input class="input" name="username" value="{{ old('username') }}"></div>
                <div x-show="p === 'gitlab'" x-cloak><label class="label">Self-hosted URL <span class="font-normal text-slate-400">(optional)</span></label><input class="input" name="url" value="{{ old('url') }}" placeholder="https://gitlab.example.com"></div>
                <div>
                    <label class="label" x-text="p === 'bitbucket' ? 'App password' : 'Access token'"></label>
                    <input class="input font-mono" type="password" name="token" required autocomplete="off">
                    <p class="hint" x-text="help[p]"></p>
                </div>
                <button class="btn btn-primary w-full">Connect</button>
            </form>
        </div>
    </section>
</x-layout>
