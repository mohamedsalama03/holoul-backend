<?php

declare(strict_types=1);

namespace App\Modules\AI\Data;

/** Untrusted provider JSON is validated before any suggestion is persisted. */
final readonly class AIResult
{
    public function __construct(public string $json, public int $inputTokens, public int $outputTokens,
        public int $costMicrousd, public ?string $operationId = null) {}
}
