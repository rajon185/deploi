<?php

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Daemon;
use App\Models\Server;
use App\Scripts\ServerScripts;
use App\Tasks\Callbacks\DeleteSubject;
use Illuminate\Http\Request;

class DaemonController extends Controller
{
    public function index(Server $server)
    {
        return view('servers.daemons', [
            'server' => $server,
            'daemons' => $server->daemons()->latest()->get(),
        ]);
    }

    public function store(Request $request, Server $server)
    {
        $data = $request->validate([
            'command' => ['required', 'string', 'max:1000', 'not_regex:/[\r\n]/'],
            'directory' => ['nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9_\/.-]*$/'],
            'user' => ['required', 'in:root,'.$server->system_user],
            'processes' => ['required', 'integer', 'between:1,50'],
            'stop_wait_seconds' => ['required', 'integer', 'between:1,3600'],
        ]);

        $daemon = $server->daemons()->create($data);
        $server->runTask('Install daemon', ServerScripts::writeDaemon($daemon), ['subject' => $daemon]);

        return back()->with('success', 'Daemon is being installed.');
    }

    public function restart(Server $server, Daemon $daemon)
    {
        $server->runTask('Restart daemon', ServerScripts::restartSupervisorProgram($daemon->programName()), ['on_finish' => null]);

        return back()->with('success', 'Daemon is restarting.');
    }

    public function destroy(Server $server, Daemon $daemon)
    {
        $daemon->update(['status' => 'deleting']);
        $server->runTask('Remove daemon', ServerScripts::removeDaemon($daemon), [
            'subject' => $daemon, 'on_finish' => DeleteSubject::class,
        ]);

        return back()->with('success', 'Daemon is being removed.');
    }
}
