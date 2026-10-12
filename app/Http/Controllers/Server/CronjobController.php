<?php

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Cronjob;
use App\Models\Server;
use App\Scripts\ServerScripts;
use App\Tasks\Callbacks\DeleteSubject;
use Illuminate\Http\Request;

class CronjobController extends Controller
{
    public function index(Server $server)
    {
        return view('servers.cronjobs', [
            'server' => $server,
            'cronjobs' => $server->cronjobs()->latest()->get(),
        ]);
    }

    public function store(Request $request, Server $server)
    {
        $data = $request->validate([
            'command' => ['required', 'string', 'max:1000', 'not_regex:/[\r\n]/'],
            'frequency' => ['required', 'string'],
            'custom_frequency' => ['required_if:frequency,custom', 'nullable', 'regex:/^(\S+\s+){4}\S+$/'],
            'user' => ['required', 'in:root,'.$server->system_user],
        ]);

        $cronjob = $server->cronjobs()->create([
            'command' => $data['command'],
            'frequency' => $data['frequency'] === 'custom' ? trim($data['custom_frequency']) : $data['frequency'],
            'user' => $data['user'],
        ]);

        $server->runTask('Install cron job', ServerScripts::writeCronjob($cronjob), ['subject' => $cronjob]);

        return back()->with('success', 'Cron job is being installed.');
    }

    public function destroy(Server $server, Cronjob $cronjob)
    {
        $cronjob->update(['status' => 'deleting']);
        $server->runTask('Remove cron job', ServerScripts::removeCronjob($cronjob), [
            'subject' => $cronjob, 'on_finish' => DeleteSubject::class,
        ]);

        return back()->with('success', 'Cron job is being removed.');
    }
}
