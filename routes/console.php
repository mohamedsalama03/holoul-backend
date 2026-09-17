<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('operations:reconcile')->everyMinute();
Schedule::command('proposals:expire --limit=100')->everyMinute();
Schedule::command('documents:expire-uploads --limit=20')->everyFiveMinutes();
Schedule::command('documents:reconcile --limit=20')->everyFiveMinutes();
Schedule::command('identity:sessions:prune')->everyFifteenMinutes();
Schedule::call(function (): void {
    file_put_contents('/tmp/holoul-scheduler-heartbeat', (string) time());
})->everyMinute();
