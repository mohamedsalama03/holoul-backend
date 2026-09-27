<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('operations:reconcile')->everyMinute();
Schedule::command('operations:heartbeat')->everyMinute();
Schedule::command('operations:observe')->everyMinute();
Schedule::command('notifications:reconcile --limit=100')->everyMinute();
Schedule::command('ai:reconcile-runs')->everyMinute();
Schedule::command('proposals:expire --limit=100')->everyMinute();
Schedule::command('documents:expire-uploads --limit=20')->everyFiveMinutes();
Schedule::command('documents:expire-project-uploads --limit=20')->everyFiveMinutes();
Schedule::command('documents:reconcile --limit=20')->everyFiveMinutes();
Schedule::command('identity:sessions:prune')->everyFifteenMinutes();
Schedule::call(function (): void {
    file_put_contents('/tmp/holoul-scheduler-heartbeat', (string) time());
})->everyMinute();

Schedule::command('identity:expire-staff-invitations')->everyMinute()->withoutOverlapping();
