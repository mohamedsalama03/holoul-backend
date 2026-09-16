<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use InvalidArgumentException;
use RuntimeException;

final class PermanentOperationFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $safeCode) !== 1) {
            throw new InvalidArgumentException('Invalid operation failure code.');
        }

        parent::__construct('The operation cannot be retried automatically.');
    }
}
