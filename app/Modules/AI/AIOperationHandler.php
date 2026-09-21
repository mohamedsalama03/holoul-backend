<?php

declare(strict_types=1);

namespace App\Modules\AI;

use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Modules\AI\Actions\GenerateAI;
use Closure;
use Illuminate\Contracts\Container\Container;

/** Resolve source storage and provider dependencies only when executing durable work. */
final readonly class AIOperationHandler implements OperationHandler
{
    public function __construct(private Container $container) {}

    public function execute(OperationClaim $operation): Closure
    {
        return $this->container->make(GenerateAI::class)->execute($operation);
    }
}
