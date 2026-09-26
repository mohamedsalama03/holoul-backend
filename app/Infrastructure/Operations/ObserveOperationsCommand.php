<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use Illuminate\Console\Command;

final class ObserveOperationsCommand extends Command
{
    protected $signature = 'operations:observe {--json : Emit the fixed machine-readable operational snapshot}';

    protected $description = 'Emit bounded redacted PostgreSQL, Redis, queue, worker, storage and recovery telemetry.';

    public function handle(OperationalSnapshot $snapshot): int
    {
        $this->line(json_encode($snapshot->collect(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
