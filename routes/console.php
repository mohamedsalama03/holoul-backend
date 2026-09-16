<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('operations:reconcile')->everyMinute();
Schedule::command('identity:sessions:prune')->everyFifteenMinutes();
Schedule::call(function (): void {
    file_put_contents('/tmp/holoul-scheduler-heartbeat', (string) time());
})->everyMinute();
