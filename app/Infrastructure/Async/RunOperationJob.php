<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RunOperationJob implements ShouldQueue
{
    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $operationId) {}

    public function handle(OperationRunner $runner): void
    {
        try {
            $runner->run($this->operationId);
        } catch (Throwable) {
            // Database outages leave durable work for reconciliation. Neither
            // provider responses nor database exception text enters queue logs.
            Log::warning('async.execution_unavailable', ['operation_id' => $this->operationId]);
        }
    }
}
