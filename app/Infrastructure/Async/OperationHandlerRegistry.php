<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use LogicException;

final class OperationHandlerRegistry
{
    /** @var array<string, OperationHandler> */
    private array $handlers = [];

    public function register(string $kind, OperationHandler $handler): void
    {
        if (isset($this->handlers[$kind])) {
            throw new LogicException('An operation handler is already registered.');
        }

        $this->handlers[$kind] = $handler;
    }

    public function for(string $kind): OperationHandler
    {
        return $this->handlers[$kind] ?? throw new PermanentOperationFailure('handler_missing');
    }
}
