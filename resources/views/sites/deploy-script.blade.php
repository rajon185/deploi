<x-layout :title="'Deploy script · '.$site->domain">
    <x-site-header :site="$site" active="deploy-script" />

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('sites.deploy-script.update', $site) }}" class="card space-y-4 p-5 lg:col-span-2" x-data="{ script: @js(old('deploy_script', $site->deploy_script ?: $site->defaultDeployScript())) }">
            @csrf @method('PUT')
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Deploy script</h2>
                <button type="button" class="text-xs text-slate-500 hover:text-indigo-600" @click="script = @js($site->defaultDeployScript())">Reset to default</button>
            </div>
            <textarea name="deploy_script" x-model="script" rows="22" spellcheck="false" class="input font-mono text-xs leading-relaxed"></textarea>
            <p class="hint">Runs as <span class="code">{{ $site->system_user }}</span> with <span class="code">bash -eo pipefail</span> - any failing command stops the deployment.
                @if($site->zero_downtime) For zero-downtime sites the new release is cloned for you before this script runs, and only made live if it succeeds.@endif
            </p>
            <button class="btn btn-primary">Save script</button>
        </form>

        <div class="card space-y-3 p-5 text-sm">
            <h2 class="font-semibold">Available variables</h2>
            <dl class="space-y-2 text-xs">
                @foreach([
                    'DEPLOI_RELEASE_PATH' => $site->zero_downtime ? 'The new release directory being built' : 'Same as DEPLOI_SITE_PATH',
                    'DEPLOI_SITE_PATH' => $site->path(),
                    'DEPLOI_BRANCH' => $site->branch ?? 'main',
                    'DEPLOI_PHP' => 'php'.$site->phpVersion(),
                    'DEPLOI_PHP_FPM' => 'php'.$site->phpVersion().'-fpm',
                    'DEPLOI_COMPOSER' => 'php'.$site->phpVersion().' /usr/local/bin/composer',
                    'DEPLOI_DOMAIN' => $site->domain,
                    'DEPLOI_DEPLOYMENT_ID' => 'Deploi deployment id',
                ] as $var => $desc)
                    <div><dt class="font-mono font-semibold">${{ $var }}</dt><dd class="text-slate-500">{{ $desc }}</dd></div>
                @endforeach
            </dl>
            <p class="text-xs text-slate-500">The deploy user may run <span class="code">sudo -n /usr/sbin/service php*-fpm reload</span> and <span class="code">sudo -n /usr/bin/supervisorctl …</span> without a password.</p>
        </div>
    </div>
</x-layout>
