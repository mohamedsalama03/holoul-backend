<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DatabaseClock
{
    public static function now(): CarbonImmutable
    {
        $value = DB::scalar('SELECT clock_timestamp()::text');

        return is_string($value) ? CarbonImmutable::parse($value)->utc() : throw new LogicException('Database clock unavailable.');
    }
}
