<?php

declare(strict_types=1);

namespace App\Modules\Audit\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Closed technical metadata only. Extend this allowlist through an explicit
 * schema review; bounded arbitrary strings are not safe audit metadata.
 */
final readonly class SafeAuditMetadata
{
    /** @var array<string, bool|int|string> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        $safe = [];

        foreach ($values as $key => $value) {
            $valid = match ($key) {
                'outcome' => in_array($value, ['succeeded', 'failed', 'denied'], true),
                'operation_id' => is_string($value) && Str::isUuid($value),
                'attempt_number' => is_int($value) && $value >= 1 && $value <= 1000,
                'duration_ms' => is_int($value) && $value >= 0 && $value <= 86_400_000,
                'affected_count' => is_int($value) && $value >= 0 && $value <= 1_000_000,
                'retryable' => is_bool($value),
                default => false,
            };

            if (! $valid || (! is_bool($value) && ! is_int($value) && ! is_string($value))) {
                throw new InvalidArgumentException('Audit metadata contains an unsupported key or value.');
            }

            $safe[$key] = $value;
        }

        $this->values = $safe;
    }

    public function toJson(): string
    {
        return json_encode($this->values, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
    }
}
