<?php

namespace App\Http\Controllers;

use App\Actions\DeploySite;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Scripts\Bash;
use App\Scripts\ServerScripts;
use App\Scripts\SiteScripts;
use App\Tasks\Callbacks\DeleteSubject;
use App\Tasks\Callbacks\RepositoryInstalled;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class SiteController extends Controller
{
    protected const DOMAIN_REGEX = '/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/';

    public function store(Request $request, Server $server)
    {
        abort_unless($server->isActive() && $server->hasNginx(), 422, 'Sites can only be added to active web servers.');

        $data = $request->validate([
            'domain' => ['required', 'string', 'regex:'.self::DOMAIN_REGEX, Rule::unique('sites')->where('server_id', $server->id)],
            'aliases' => ['nullable', 'string', 'max:1000'],
            'project_type' => ['required', Rule::in(array_keys(Site::PROJECT_TYPES))],
            'web_directory' => ['nullable', 'string', 'max:120', 'regex:/^\/?[A-Za-z0-9_\/.-]*$/'],
            'php_version' => ['nullable', Rule::in($server->installed_php_versions ?? [])],
            'app_port' => ['nullable', 'required_if:project_type,nodejs', 'integer', 'between:1024,65535'],
            'zero_downtime' => ['boolean'],
        ]);

        $aliases = $this->parseAliases($data['aliases'] ?? '');

        $site = $server->sites()->create([
            'domain' => strtolower($data['domain']),
            'aliases' => $aliases,
            'project_type' => $data['project_type'],
            'web_directory' => $this->normalizeWebDirectory($data['web_directory'] ?? ($data['project_type'] === 'laravel' ? 'public' : ''), "/home/{$server->system_user}/".strtolower($data['domain'])),
            'php_version' => $data['php_version'] ?? $server->php_version,
            'app_port' => $data['app_port'] ?? null,
            'zero_downtime' => $request->boolean('zero_downtime'),
            'system_user' => $server->system_user,
        ]);
        $site->update(['deploy_script' => $site->defaultDeployScript()]);

        $server->runTask("Create site {$site->domain}", SiteScripts::create($site), ['subject' => $site]);

        return redirect()->route('sites.show', $site)->with('success', "Site {$site->domain} is being created.");
    }

    public function show(Site $site)
    {
        $server = $site->server;

        return view('sites.show', [
            'site' => $site,
            'server' => $server,
            'deployments' => $site->deployments()->latest()->limit(10)->get(),
            'sourceProviders' => $server->user->sourceProviders()->get(),
            'databases' => $server->databases()->where('status', 'active')->get(),
        ]);
    }

    public function deploy(Site $site)
    {
        // Imported sites can run their deploy script even without a git checkout.
        abort_unless($site->imported || ($site->hasRepository() && $site->repository_status === 'installed'), 422, 'Install a repository first.');

        $deployment = DeploySite::run($site, 'manual');

        return redirect()->route('sites.deployments.show', [$site, $deployment]);
    }

    public function deployment(Site $site, Deployment $deployment)
    {
        return view('sites.deployment', [
            'site' => $site,
            'server' => $site->server,
            'deployment' => $deployment->load('task'),
        ]);
    }

    public function rollback(Site $site, Deployment $deployment)
    {
        abort_unless($site->zero_downtime && $deployment->release && $deployment->status === 'finished', 422);

        $site->server->runTask("Roll back {$site->domain} to {$deployment->release}", SiteScripts::rollback($site, $deployment->release), [
            'user' => $site->system_user, 'on_finish' => null,
        ]);

        return back()->with('success', "Rolling back to release {$deployment->release}.");
    }

    // ------------------------------------------------------------ Repository

    public function installRepository(Request $request, Site $site)
    {
        // Installing wipes the site directory - never on a site Deploi didn't create.
        abort_if($site->imported, 422, 'Imported sites keep their existing files. Deploi deploys them with "git pull" if the directory is a git checkout.');

        $server = $site->server;

        // The Git-host tiles are styled radio buttons; if none came through, work it out.
        if (! $request->filled('repository_provider')) {
            $account = $request->filled('source_provider_id') ? auth()->user()->sourceProviders()->find($request->input('source_provider_id')) : null;
            $request->merge(['repository_provider' => $account?->provider
                ?? (preg_match('#^(git@|ssh://|https://)#', (string) $request->input('repository')) ? 'custom' : 'github')]);
        }

        $data = $request->validate([
            'repository_provider' => ['required', Rule::in(array_keys(Site::REPOSITORY_PROVIDERS))],
            'source_provider_id' => ['nullable', Rule::exists('source_providers', 'id')->where('user_id', auth()->id())],
            'repository' => ['required', 'string', 'max:255', $request->input('repository_provider') === 'custom'
                ? 'regex:/^(git@|ssh:\/\/|https:\/\/)[^\s\'"`$;|&]+$/'
                : 'regex:/^[A-Za-z0-9_.\-]+(\/[A-Za-z0-9_.\-]+)+$/'],
            'branch' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.\/-]+$/'],
            'database_id' => ['nullable', Rule::exists('databases', 'id')->where('server_id', $server->id)],
            'auto_deploy' => ['boolean'],
        ], ['repository.regex' => 'Use "owner/repository" (or a full git URL for custom repositories).'], ['repository_provider' => 'Git host']);

        $source = ! empty($data['source_provider_id']) ? auth()->user()->sourceProviders()->find($data['source_provider_id']) : null;

        // Authorise the server's key first: if the provider refuses, nothing about the site has changed
        // and the install form is shown again with what was typed.
        if ($source) {
            try {
                $source->driver()->authorizeKey("Deploi: {$server->name}", $server->deploy_public_key);
            } catch (Throwable $e) {
                return back()->withInput()->with('error', 'Could not add the server key to '.$source->label.': '.$this->apiError($e));
            }
        }

        $site->update([
            'repository_provider' => $data['repository_provider'],
            'source_provider_id' => $source?->id,
            'repository' => $data['repository'],
            'branch' => $data['branch'],
            'repository_status' => 'installing',
        ]);

        $site->server->runTask("Install repository on {$site->domain}", SiteScripts::installRepository($site, $server->databases()->find($data['database_id'] ?? null)), [
            'user' => $site->system_user,
            'subject' => $site,
            'on_finish' => RepositoryInstalled::class,
            'timeout' => 900,
        ]);

        if ($request->boolean('auto_deploy')) {
            $this->enableAutoDeploy($site);
        }

        return back()->with('success', 'Repository is being installed. The first deployment will start automatically.');
    }

    public function removeRepository(Site $site)
    {
        $this->disableAutoDeploy($site);

        // For imported sites only forget the settings; their files are not Deploi's to delete.
        if (! $site->imported) {
            $site->server->runTask("Remove repository from {$site->domain}", SiteScripts::removeRepository($site), [
                'user' => $site->system_user, 'on_finish' => null,
            ]);
        }

        $site->update(['repository' => null, 'branch' => null, 'repository_provider' => null, 'source_provider_id' => null, 'repository_status' => null]);

        return back()->with('success', 'Repository removed.');
    }

    public function toggleAutoDeploy(Site $site)
    {
        $site->auto_deploy ? $this->disableAutoDeploy($site) : $this->enableAutoDeploy($site);

        return back()->with('success', $site->auto_deploy ? 'Auto-deploy enabled.' : 'Auto-deploy disabled.');
    }

    protected function enableAutoDeploy(Site $site): void
    {
        if ($site->sourceProvider && ! $site->provider_webhook_id) {
            try {
                $id = $site->sourceProvider->driver()->createWebhook($site->repository, $site->webhookUrl());
                $site->provider_webhook_id = $id;
            } catch (Throwable $e) {
                session()->flash('error', 'Auto-deploy is on, but the webhook could not be created automatically ('.$this->apiError($e).'). Add the deploy URL to your repository manually.');
            }
        }

        $site->auto_deploy = true;
        $site->save();
    }

    protected function disableAutoDeploy(Site $site): void
    {
        if ($site->sourceProvider && $site->provider_webhook_id) {
            try {
                $site->sourceProvider->driver()->deleteWebhook($site->repository, $site->provider_webhook_id);
            } catch (Throwable) {
                // The hook may have been deleted on the provider already.
            }
        }

        $site->update(['auto_deploy' => false, 'provider_webhook_id' => null]);
    }

    // ------------------------------------------------------------ Deploy script

    public function editDeployScript(Site $site)
    {
        return view('sites.deploy-script', ['site' => $site, 'server' => $site->server]);
    }

    public function updateDeployScript(Request $request, Site $site)
    {
        $data = $request->validate(['deploy_script' => ['required', 'string', 'max:20000']]);

        $site->update($data);

        return back()->with('success', 'Deploy script saved.');
    }

    public function regenerateWebhook(Site $site)
    {
        $site->update(['deploy_webhook_token' => Str::random(48)]);

        return back()->with('success', 'Deploy webhook URL regenerated. Update it wherever it was used.');
    }

    // ------------------------------------------------------------ Environment

    public function editEnvironment(Site $site)
    {
        $error = null;
        $contents = '';

        try {
            $contents = $site->server->ssh()->get(SiteScripts::envPath($site)) ?? '';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('sites.environment', ['site' => $site, 'server' => $site->server, 'contents' => $contents, 'error' => $error]);
    }

    public function updateEnvironment(Request $request, Site $site)
    {
        $data = $request->validate(['contents' => ['nullable', 'string', 'max:100000'], 'apply' => ['boolean']]);
        $path = SiteScripts::envPath($site);

        try {
            $ssh = $site->server->ssh();
            // Browsers drop the textarea's final newline; put it back so later appends stay on their own line.
            $contents = rtrim(str_replace("\r\n", "\n", $data['contents'] ?? ''), "\n");
            $ssh->put($path, $contents === '' ? '' : $contents."\n", 0640);
            $ssh->exec('chown '.Bash::q("{$site->system_user}:{$site->system_user}").' '.Bash::q($path))->throw();
        } catch (Throwable $e) {
            return back()->with('error', 'Could not save .env: '.$e->getMessage());
        }

        if (! $site->isLaravel()) {
            return back()->with('success', '.env saved. Restart or redeploy the app if it only reads .env on start-up.');
        }

        if (! $request->boolean('apply')) {
            return back()->with('success', '.env saved. Run "php artisan config:cache" (Commands tab) or deploy for changes to take effect.');
        }

        // Laravel only sees the new values once a cached config is rebuilt, and queue workers keep
        // the old ones until they restart. Logged in the Commands tab so the output is visible.
        $command = $site->commands()->create(['user_id' => $request->user()->id, 'command' => SiteScripts::APPLY_ENV_COMMAND]);
        $task = $site->server->runTask("Apply .env on {$site->domain}", SiteScripts::command($site, SiteScripts::APPLY_ENV_COMMAND), [
            'user' => $site->system_user, 'subject' => $command, 'on_finish' => null, 'timeout' => 120,
        ]);
        $command->update(['server_task_id' => $task->id]);

        return back()->with('success', '.env saved. Rebuilding the config cache and restarting queue workers - output is in the Commands tab.');
    }

    // ------------------------------------------------------------ Logs

    public function logs(Request $request, Site $site)
    {
        $paths = SiteScripts::logPaths($site);
        $which = $request->query('log', array_key_first($paths));
        abort_unless(isset($paths[$which]), 404);

        $output = null;
        $error = null;

        try {
            $result = $site->server->ssh()->exec('tail -n 300 '.Bash::q($paths[$which]).' 2>&1 || true', timeout: 20);
            $output = $result->output;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('sites.logs', compact('site', 'paths', 'which', 'output', 'error') + ['server' => $site->server]);
    }

    // ------------------------------------------------------------ Settings

    public function settings(Site $site)
    {
        return view('sites.settings', ['site' => $site->load('redirects'), 'server' => $site->server]);
    }

    public function update(Request $request, Site $site)
    {
        $server = $site->server;

        if ($site->imported) {
            return $this->updateImported($request, $site);
        }

        $data = $request->validate([
            'aliases' => ['nullable', 'string', 'max:1000'],
            'web_directory' => ['nullable', 'string', 'max:120', 'regex:/^\/?[A-Za-z0-9_\/.-]*$/'],
            'php_version' => ['nullable', Rule::in($server->installed_php_versions ?? [])],
            'app_port' => ['nullable', 'integer', 'between:1024,65535'],
            'keep_releases' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $site->update([
            'aliases' => $this->parseAliases($data['aliases'] ?? ''),
            'web_directory' => $this->normalizeWebDirectory($data['web_directory'] ?? '', $site->path()),
            'php_version' => $data['php_version'] ?? $site->php_version,
            'app_port' => $data['app_port'] ?? $site->app_port,
            'keep_releases' => $data['keep_releases'] ?? $site->keep_releases,
        ]);

        $this->rewriteNginx($site);

        return back()->with('success', 'Settings saved - Nginx is being updated.');
    }

    /**
     * Change the site's primary domain (its name in Deploi) and/or its "Visit" link.
     * The site keeps its folder, certificates and webhook URL.
     */
    public function updateDomain(Request $request, Site $site)
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'regex:'.self::DOMAIN_REGEX,
                Rule::unique('sites')->where('server_id', $site->server_id)->ignore($site->id)],
            'keep_old_as_alias' => ['boolean'],
            'visit_url' => ['nullable', 'url:http,https', 'max:255'],
        ], ['visit_url.url' => 'The visit link must be a full URL starting with http:// or https://.']);

        $site->visit_url = $data['visit_url'] ?: null;
        $newDomain = strtolower($data['domain']);
        $oldDomain = $site->domain;

        if ($newDomain === $oldDomain) {
            $site->save();

            return back()->with('success', 'Visit link saved.');
        }

        // Pin everything that was derived from the old domain, so renaming moves nothing on disk.
        $site->directory ??= $site->path();
        foreach ($site->certificates as $certificate) {
            $certificate->update(['cert_name' => $certificate->name()]);
        }

        $aliases = collect($site->aliases ?? [])->reject(fn ($a) => $a === $newDomain);
        if ($request->boolean('keep_old_as_alias')) {
            $aliases->prepend($oldDomain);
        }

        $site->domain = $newDomain;
        $site->aliases = $aliases->unique()->values()->all();
        $site->save();

        if ($site->managesWebserverConfig()) {
            $site->load('redirects');
            $site->server->runTask("Rename {$oldDomain} to {$newDomain}", SiteScripts::renameVhost($site, $oldDomain), ['on_finish' => null]);
        }

        return back()->with('success', "Renamed to {$newDomain}"
            .($site->managesWebserverConfig() ? ' - Nginx is being updated.' : '. The site\'s own web server config was not changed.')
            .' Remember to update APP_URL in the Environment tab if it uses the old domain.');
    }

    /** Imported sites: only Deploi's own view of the site changes - the vhost on the server is left alone. */
    protected function updateImported(Request $request, Site $site)
    {
        $data = $request->validate([
            'directory' => ['required', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9_\/.-]*$/'],
            'system_user' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'php_version' => ['nullable', 'string', 'regex:/^\d+\.\d+$/'],
            'project_type' => ['required', Rule::in(array_keys(Site::PROJECT_TYPES))],
            'branch' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.\/-]+$/'],
        ]);

        $site->update([
            'directory' => rtrim($data['directory'], '/') ?: '/',
            'system_user' => $data['system_user'],
            'php_version' => $data['php_version'] ?: $site->php_version,
            'project_type' => $data['project_type'],
            'branch' => $data['branch'] ?? $site->branch,
        ]);

        return back()->with('success', 'Settings saved. The site\'s web server config was not changed.');
    }

    public function addLaravelScheduler(Site $site)
    {
        $server = $site->server;
        $command = 'php'.$site->phpVersion().' '.$site->currentPath().'/artisan schedule:run';

        $cronjob = $server->cronjobs()->create(['command' => $command, 'frequency' => '* * * * *', 'user' => $site->system_user]);
        $server->runTask('Install cron job', ServerScripts::writeCronjob($cronjob), ['subject' => $cronjob]);

        return back()->with('success', 'Laravel scheduler cron job is being installed.');
    }

    public function storeRedirect(Request $request, Site $site)
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:255', 'regex:/^\/[^\s;{}\'"]*$/'],
            'to' => ['required', 'string', 'max:500', 'regex:/^[^\s;{}\'"]+$/'],
            'type' => ['required', 'in:301,302'],
        ]);
        abort_unless($site->managesWebserverConfig(), 422, 'Redirects need a Deploi-managed web server config.');

        $site->redirects()->create($data);
        $this->rewriteNginx($site);

        return back()->with('success', 'Redirect added.');
    }

    public function destroyRedirect(Site $site, int $redirect)
    {
        $site->redirects()->whereKey($redirect)->delete();
        $this->rewriteNginx($site);

        return back()->with('success', 'Redirect removed.');
    }

    public function destroy(Request $request, Site $site)
    {
        $request->validate(['confirm' => ['required', Rule::in([$site->domain])]], ['confirm.in' => 'Type the domain to confirm.']);

        $this->disableAutoDeploy($site);

        if ($site->imported) {
            // Only remove what Deploi itself added (queue workers); the site's files and vhost stay.
            foreach ($site->queueWorkers as $worker) {
                $site->server->runTask("Remove queue worker from {$site->domain}", ServerScripts::removeQueueWorker($worker), ['on_finish' => null]);
            }
            $site->delete();

            return redirect()->route('servers.show', $site->server)->with('success', "{$site->domain} was removed from Deploi. Nothing was deleted on the server.");
        }

        $site->update(['status' => 'deleting']);
        $site->server->runTask("Delete site {$site->domain}", SiteScripts::delete($site->load('certificates', 'queueWorkers')), [
            'subject' => $site, 'on_finish' => DeleteSubject::class,
        ]);

        return redirect()->route('servers.show', $site->server)->with('success', "Site {$site->domain} is being deleted.");
    }

    // ------------------------------------------------------------ Helpers

    protected function rewriteNginx(Site $site): void
    {
        if (! $site->managesWebserverConfig()) {
            return;
        }

        $site->refresh()->load('redirects');
        $site->server->runTask("Update Nginx for {$site->domain}", SiteScripts::nginxConfigFor($site, $site->activeCertificate()), ['on_finish' => null]);
    }

    /**
     * The web directory is relative to the site folder ("/public"). People naturally paste a full
     * path, so strip the site folder if it's there and refuse any other absolute server path.
     */
    protected function normalizeWebDirectory(string $value, string $sitePath): string
    {
        $value = '/'.trim(trim($value), '/');

        foreach ([$sitePath.'/current', $sitePath] as $prefix) {
            if ($value === $prefix || str_starts_with($value, $prefix.'/')) {
                $value = '/'.ltrim(substr($value, strlen($prefix)), '/');
                break;
            }
        }

        if (preg_match('#^/(home|var|srv|opt|usr|etc|root|tmp)(/|$)#', $value) || str_contains($value, '..')) {
            throw ValidationException::withMessages([
                'web_directory' => "The web directory is relative to the site folder ({$sitePath}) - use e.g. \"/public\", or \"/\" for the folder itself.",
            ]);
        }

        return rtrim($value, '/') ?: '/';
    }

    protected function parseAliases(string $raw): array
    {
        $aliases = collect(preg_split('/[\s,]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY))->unique()->values();

        foreach ($aliases as $alias) {
            if (! preg_match(self::DOMAIN_REGEX, $alias)) {
                throw ValidationException::withMessages(['aliases' => "\"{$alias}\" is not a valid domain."]);
            }
        }

        return $aliases->all();
    }

    protected function apiError(Throwable $e): string
    {
        if ($e instanceof RequestException) {
            return $e->response->json('message') ?? $e->response->json('error.message') ?? 'HTTP '.$e->response->status();
        }

        return $e->getMessage();
    }
}
