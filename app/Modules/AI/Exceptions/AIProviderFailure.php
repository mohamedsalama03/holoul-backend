<?php

declare(strict_types=1);

namespace App\Modules\AI\Exceptions;

use RuntimeException;

/** Adapters report only bounded codes; raw provider bodies never enter exceptions/logs. */
final class AIProviderFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode, public readonly bool $retryable = false,
        public readonly int $retryAfterSeconds = 0, public readonly bool $uncertain = false,
        public readonly ?string $operationId = null)
    {
        parent::__construct('AI provider operation failed.');
    }
}
