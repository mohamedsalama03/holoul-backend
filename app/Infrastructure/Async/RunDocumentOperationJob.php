<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Illuminate\Contracts\Queue\ShouldQueue;

final class RunDocumentOperationJob implements ShouldQueue
{
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $operationId) {}

    public function handle(OperationRunner $runner): void
    {
        (new RunOperationJob($this->operationId))->handle($runner);
    }
}
