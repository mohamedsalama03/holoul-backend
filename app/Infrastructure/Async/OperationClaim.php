<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

final readonly class OperationClaim
{
    /** @param array<string, string> $references */
    public function __construct(
        public string $id,
        public string $kind,
        public array $references,
        public int $fence,
        public int $attempt,
        public ?string $requestId,
    ) {}
}
