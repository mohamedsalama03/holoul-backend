<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use Carbon\CarbonImmutable;

final class StaffData
{
    public static function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \LogicException('Invalid staff record.');
        }

        return $value;
    }

    public static function date(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse(self::text($value))->toIso8601String();
    }
}
