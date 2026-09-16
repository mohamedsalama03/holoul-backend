<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Closure;

interface OperationHandler
{
    /**
     * Prepare outside the database transaction. Return a database-only writer:
     * the runner invokes it with its valid fence locked, in the same transaction
     * as success. Future result tables must uniquely reference the operation ID.
     *
     * External effects require provider idempotency/reconciliation. An ambiguous
     * result must throw PermanentOperationFailure, never a blind automatic retry.
     *
     * @return Closure(): void
     */
    public function execute(OperationClaim $operation): Closure;
}
