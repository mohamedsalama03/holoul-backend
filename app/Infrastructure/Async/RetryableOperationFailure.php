<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use RuntimeException;

/** A known rejection, never an ambiguous external side effect. */
final class RetryableOperationFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode, public readonly int $retryAfterSeconds = 0)
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,79}\z/D', $safeCode) !== 1 || $retryAfterSeconds < 0 || $retryAfterSeconds > 3600) {
            throw new \InvalidArgumentException('Invalid retry policy.');
        }
        parent::__construct('A retryable external operation was rejected.');
    }
}
