<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\QueueWorker;
use App\Models\Site;
use App\Scripts\ServerScripts;
use App\Tasks\Callbacks\DeleteSubject;
use Illuminate\Http\Request;

class QueueWorkerController extends Controller
{
    public function index(Site $site)
    {
        return view('sites.queue', [
            'site' => $site,
            'server' => $site->server,
            'workers' => $site->queueWorkers()->latest()->get(),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $data = $request->validate([
            'connection' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'queue' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_,:-]+$/'],
            'processes' => ['required', 'integer', 'between:1,50'],
            'timeout' => ['required', 'integer', 'between:0,86400'],
            'sleep' => ['required', 'integer', 'between:0,3600'],
            'tries' => ['required', 'integer', 'between:0,100'],
            'memory' => ['required', 'integer', 'between:32,8192'],
        ]);

        $worker = $site->queueWorkers()->create($data);
        $site->server->runTask("Start queue worker for {$site->domain}", ServerScripts::writeQueueWorker($worker), ['subject' => $worker]);

        return back()->with('success', 'Queue worker is being started.');
    }

    public function restart(Site $site, QueueWorker $queueWorker)
    {
        $worker = $queueWorker;

        $site->server->runTask("Restart queue worker for {$site->domain}", ServerScripts::restartSupervisorProgram($worker->programName()), ['on_finish' => null]);

        return back()->with('success', 'Queue worker is restarting.');
    }

    public function destroy(Site $site, QueueWorker $queueWorker)
    {
        $worker = $queueWorker;

        $worker->update(['status' => 'deleting']);
        $site->server->runTask("Remove queue worker from {$site->domain}", ServerScripts::removeQueueWorker($worker), [
            'subject' => $worker, 'on_finish' => DeleteSubject::class,
        ]);

        return back()->with('success', 'Queue worker is being removed.');
    }
}
