<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Throwable;

/** Preserve PostgreSQL SQLSTATE recognition through Laravel's nested-transaction wrapper. */
final class WrappedConcurrencyErrors extends ConcurrencyErrorDetector
{
    public function causedByConcurrencyError(Throwable $e): bool
    {
        while ($e instanceof DeadlockException && $e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return parent::causedByConcurrencyError($e);
    }
}
