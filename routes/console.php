<?php

use Illuminate\Support\Facades\Schedule;

// Ping every active server and flag the ones Deploi can no longer reach.
Schedule::command('deploi:check-servers')->everyFiveMinutes()->withoutOverlapping();

// Keep the task log from growing forever.
Schedule::command('deploi:prune-tasks')->daily();
