<?php

namespace App\Tasks\Callbacks;

use App\Models\ServerTask;

interface TaskCallback
{
    public function handle(ServerTask $task): void;
}
