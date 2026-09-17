<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Illuminate\Support\Facades\Config;

/** Transport/execution policy remains infrastructure-owned and identifier-only. */
final class OperationPolicy
{
    public static function documents(string $kind): bool
    {
        return in_array($kind, ['documents.scan', 'documents.delete', 'documents.delete_orphan'], true);
    }

    public static function leaseSeconds(string $kind): int
    {
        return self::documents($kind) ? 150 : Config::integer('async.lease_seconds');
    }

    public static function maxAttempts(string $kind): int
    {
        return self::documents($kind) ? 3 : Config::integer('async.max_attempts');
    }

    public static function backoffSeconds(string $kind, int $attempt): int
    {
        $delays = self::documents($kind) ? [30, 120] : [5, 30, 120, 300];

        return $delays[min(max(0, $attempt - 1), count($delays) - 1)];
    }
}
