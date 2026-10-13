<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Scripts\SiteScripts;
use Illuminate\Http\Request;

/** The site "Commands" tab: run one-off commands in the site's directory, like Forge. */
class CommandController extends Controller
{
    public const TIMEOUTS = [60 => '1 minute', 300 => '5 minutes', 900 => '15 minutes', 3600 => '1 hour'];

    public function index(Request $request, Site $site)
    {
        $commands = $site->commands()->with('task', 'user')->latest('id')->paginate(15);
        $selected = $request->query('command')
            ? $site->commands()->with('task')->find($request->query('command'))
            : $commands->first();

        return view('sites.commands', [
            'site' => $site,
            'server' => $site->server,
            'commands' => $commands,
            'selected' => $selected,
            'presets' => $this->presets($site),
            'timeouts' => self::TIMEOUTS,
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $data = $request->validate([
            'command' => ['required', 'string', 'max:5000'],
            'timeout' => ['nullable', 'integer', 'in:'.implode(',', array_keys(self::TIMEOUTS))],
        ]);

        $command = trim(str_replace("\r\n", "\n", $data['command']));

        $siteCommand = $site->commands()->create([
            'user_id' => $request->user()->id,
            'command' => $command,
        ]);

        $task = $site->server->runTask('Command on '.$site->domain.': '.str($command)->before("\n")->limit(60), SiteScripts::command($site, $command), [
            'user' => $site->system_user,
            'subject' => $siteCommand,
            'on_finish' => null,
            'timeout' => (int) ($data['timeout'] ?? 300),
        ]);

        $siteCommand->update(['server_task_id' => $task->id]);

        return redirect()->route('sites.commands', [$site, 'command' => $siteCommand->id]);
    }

    /** One-click commands that make sense for the site's project type. */
    protected function presets(Site $site): array
    {
        return match ($site->project_type) {
            'laravel' => [
                'php artisan migrate --force' => 'Run migrations',
                'php artisan optimize:clear' => 'Clear all caches',
                'php artisan optimize' => 'Cache config, routes & views',
                'php artisan queue:restart' => 'Restart queue workers',
                'php artisan storage:link' => 'Link storage',
                'php artisan down' => 'Maintenance mode on',
                'php artisan up' => 'Maintenance mode off',
                'composer install --no-dev --optimize-autoloader' => 'Composer install',
                'php artisan about' => 'App info',
            ],
            'wordpress' => [
                'ls -la' => 'List files',
                'php -v' => 'PHP version',
                'du -sh wp-content/uploads' => 'Uploads size',
            ],
            'nodejs' => [
                'npm ci && npm run build' => 'Install & build',
                'node -v && npm -v' => 'Node version',
            ],
            default => [
                'ls -la' => 'List files',
                'git status' => 'Git status',
                'git log -5 --oneline' => 'Recent commits',
            ],
        };
    }
}
