<x-layout title="New server">
    <x-slot:header><h1 class="text-lg font-semibold">New server</h1></x-slot:header>

    <form method="POST" action="{{ route('servers.store') }}" class="space-y-6"
          x-data="serverForm(@js($providers->map(fn ($p) => ['id' => $p->id, 'provider' => $p->provider])->values()), @js([
              'regions' => route('account.server-providers.regions', '__ID__'),
              'sizes' => route('account.server-providers.sizes', '__ID__'),
              'existing' => route('account.server-providers.servers', '__ID__'),
          ]), @js(old() + ['name' => 'web-'.Str::lower(Str::random(4)), 'provider' => request('import') ? 'import' : null]))">
        @csrf

        {{-- Provider --}}
        <div class="card p-6">
            <h2 class="font-semibold">Where should the server live?</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                @foreach($providers as $provider)
                    <label class="cursor-pointer rounded-lg border p-4 transition" :class="provider === '{{ $provider->id }}' ? 'border-indigo-500 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:border-slate-300'">
                        <input type="radio" name="provider" value="{{ $provider->id }}" x-model="provider" class="sr-only">
                        <span class="block font-medium">{{ \App\Services\Providers\ProviderManager::name($provider->provider) }}</span>
                        <span class="block text-xs text-slate-500">{{ $provider->label }}</span>
                    </label>
                @endforeach
                <label class="cursor-pointer rounded-lg border p-4 transition" :class="provider === 'custom' ? 'border-indigo-500 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:border-slate-300'">
                    <input type="radio" name="provider" value="custom" x-model="provider" class="sr-only">
                    <span class="block font-medium">Custom server (SSH)</span>
                    <span class="block text-xs text-slate-500">Any fresh Ubuntu 22.04/24.04 VPS: AWS, OVH, a droplet you made yourself…</span>
                </label>
                <label class="cursor-pointer rounded-lg border p-4 transition" :class="provider === 'import' ? 'border-indigo-500 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:border-slate-300'">
                    <input type="radio" name="provider" value="import" x-model="provider" class="sr-only">
                    <span class="block font-medium">Existing server (import)</span>
                    <span class="block text-xs text-slate-500">Already running sites? Connect it as-is - nothing is installed or changed. Deploi scans it and shows its websites.</span>
                </label>
            </div>
            @if($providers->isEmpty())
                <p class="hint mt-3">Want Deploi to create droplets for you? <a href="{{ route('account.providers') }}" class="text-indigo-600">Connect DigitalOcean or another provider</a>.</p>
            @endif
            <input type="hidden" name="server_provider_id" :value="isImport ? importAccount : (isCustom ? '' : provider)">
        </div>

        {{-- Import: pick from a provider account --}}
        <template x-if="isImport && providers.length">
            <div class="card space-y-4 p-6">
                <div>
                    <h2 class="font-semibold">Pick a server from your provider account <span class="font-normal text-slate-400">(optional)</span></h2>
                    <p class="text-sm text-slate-500">Fills in the name and IP, and lets Deploi reboot it through the provider's API.</p>
                </div>
                <select class="input sm:w-80" x-model="importAccount">
                    <option value="">Don't link to a provider</option>
                    @foreach($providers as $provider)
                        <option value="{{ $provider->id }}">{{ \App\Services\Providers\ProviderManager::name($provider->provider) }} - {{ $provider->label }}</option>
                    @endforeach
                </select>
                <p x-show="loadingExisting" class="text-sm text-slate-500">Loading servers…</p>
                <p x-show="existingError" x-text="'Provider API error: ' + existingError" class="text-sm text-red-600"></p>
                <div x-show="existing.length" class="grid gap-2 sm:grid-cols-2">
                    <template x-for="m in existing" :key="m.id">
                        <button type="button" @click="pick(m)" :disabled="m.in_deploi"
                                class="rounded-lg border p-3 text-left text-sm transition disabled:cursor-not-allowed disabled:opacity-50"
                                :class="picked === m.id ? 'border-indigo-500 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:border-slate-300'">
                            <span class="block font-medium" x-text="m.name"></span>
                            <span class="block font-mono text-xs text-slate-500" x-text="[m.ip, m.region, m.size].filter(Boolean).join(' · ')"></span>
                            <span class="text-xs" :class="m.in_deploi ? 'text-emerald-700' : 'text-slate-400'" x-text="m.in_deploi ? 'Already in Deploi' : m.status"></span>
                        </button>
                    </template>
                </div>
                <p x-show="importAccount && !loadingExisting && !existingError && !existing.length" class="text-sm text-slate-500">No servers found in this account.</p>
                <input type="hidden" name="provider_server_id" :value="picked">
            </div>
        </template>

        {{-- Details --}}
        <div class="card grid gap-5 p-6 sm:grid-cols-2">
            <div>
                <label class="label" for="name">Server name</label>
                <input class="input" id="name" name="name" x-model="name" required>
            </div>
            <div x-show="!isImport">
                <label class="label" for="type">Server type</label>
                <select class="input" id="type" name="type" x-model="type">
                    @foreach(\App\Models\Server::TYPES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <template x-if="isCloud">
                <div class="contents">
                    <div>
                        <label class="label" for="region">Region</label>
                        <select class="input" id="region" name="region" x-model="region" :disabled="loadingRegions" required>
                            <option value="" x-text="loadingRegions ? 'Loading regions…' : 'Choose a region'"></option>
                            <template x-for="(label, id) in regions" :key="id"><option :value="id" x-text="label" :selected="id === region"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="size">Size</label>
                        <select class="input" id="size" name="size" x-model="size" :disabled="!region || loadingSizes" required>
                            <option value="" x-text="loadingSizes ? 'Loading sizes…' : (region ? 'Choose a size' : 'Pick a region first')"></option>
                            <template x-for="(label, id) in sizes" :key="id"><option :value="id" x-text="label" :selected="id === size"></option></template>
                        </select>
                    </div>
                    <p x-show="error" x-text="'Provider API error: ' + error" class="text-sm text-red-600 sm:col-span-2"></p>
                </div>
            </template>

            <template x-if="isCustom || isImport">
                <div class="contents">
                    <div>
                        <label class="label" for="ip_address">IP address or hostname</label>
                        <input class="input" id="ip_address" name="ip_address" x-model="ip" placeholder="203.0.113.10" required>
                        <p class="hint">Testing locally with Docker? Use <span class="code">sandbox</span> (see README).</p>
                    </div>
                    <div>
                        <label class="label" for="ssh_port">SSH port</label>
                        <input class="input" id="ssh_port" type="number" name="ssh_port" value="{{ old('ssh_port', 22) }}">
                        <p class="hint">Deploi logs in as <span class="code">root</span> with its own generated key.</p>
                    </div>
                </div>
            </template>
        </div>

        {{-- Software --}}
        <div class="card grid gap-5 p-6 sm:grid-cols-2" x-show="!isImport">
            <h2 class="font-semibold sm:col-span-2">Software</h2>
            <div x-show="hasPhp">
                <label class="label" for="php_version">PHP version</label>
                <select class="input" id="php_version" name="php_version">
                    @foreach(\App\Models\Server::PHP_VERSIONS as $v)
                        <option value="{{ $v }}" @selected(old('php_version', '8.3') === $v)>PHP {{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="hasDb">
                <label class="label" for="database_type">Database</label>
                <select class="input" id="database_type" name="database_type">
                    <option value="">None</option>
                    @foreach(\App\Models\Server::DATABASES as $value => $label)
                        <option value="{{ $value }}" @selected(old('database_type', 'mysql') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-wrap gap-6 sm:col-span-2">
                <input type="hidden" name="install_redis" value="0">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="install_redis" value="1" class="rounded" @checked(old('install_redis', true))> Redis</label>
                <input type="hidden" name="install_node" value="0">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="install_node" value="1" class="rounded" @checked(old('install_node', true))> Node.js 22 + npm</label>
            </div>
            <p class="hint sm:col-span-2">Every server also gets Nginx (web/LB types), Composer, Supervisor, UFW firewall, fail2ban, Certbot and automatic security updates.</p>
        </div>

        <div class="flex justify-end">
            <button class="btn btn-primary" x-text="isImport ? 'Add existing server' : 'Create server'">Create server</button>
        </div>
    </form>
</x-layout>
