<?php

namespace App\Http\Controllers\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerTask;

class TaskController extends Controller
{
    public function index(Server $server)
    {
        return view('servers.tasks', [
            'server' => $server,
            'tasks' => $server->tasks()->latest()->paginate(25),
        ]);
    }

    public function show(Server $server, ServerTask $task)
    {
        return view('servers.task', ['server' => $server, 'task' => $task]);
    }

    /** Polled by the live log viewer. */
    public function output(Server $server, ServerTask $task)
    {
        return response()->json([
            'status' => $task->status,
            'exit_code' => $task->exit_code,
            'output' => $task->output ?? '',
            'running' => $task->isRunning(),
            'duration' => $task->duration(),
        ]);
    }
}
