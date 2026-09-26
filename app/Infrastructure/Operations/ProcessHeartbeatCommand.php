<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use Illuminate\Console\Command;

final class ProcessHeartbeatCommand extends Command
{
    protected $signature = 'operations:heartbeat';

    protected $description = 'Record the bounded scheduler heartbeat without exposing instance identifiers.';

    public function handle(ProcessTelemetry $process): int
    {
        $process->heartbeat('scheduler');

        return self::SUCCESS;
    }
}
