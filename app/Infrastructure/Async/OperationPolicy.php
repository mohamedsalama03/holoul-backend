<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Illuminate\Support\Facades\Config;

/** Transport/execution policy remains infrastructure-owned and identifier-only. */
final class OperationPolicy
{
    public static function queue(string $kind): string
    {
        return match (true) {
            self::documents($kind) => 'documents',
            $kind === 'ai.generate' => 'ai',
            in_array($kind, ['notifications.email', 'contact.email'], true) => 'notifications',
            default => 'default',
        };
    }

    public static function documents(string $kind): bool
    {
        return in_array($kind, ['documents.scan', 'documents.delete', 'documents.delete_orphan', 'portfolio.process_image'], true);
    }

    public static function leaseSeconds(string $kind): int
    {
        return self::documents($kind) || $kind === 'ai.generate' ? 150 : Config::integer('async.lease_seconds');
    }

    public static function maxAttempts(string $kind): int
    {
        return self::documents($kind) ? 3 : Config::integer('async.max_attempts');
    }

    public static function backoffSeconds(string $kind, int $attempt): int
    {
        $delays = match (true) {
            self::documents($kind) => [30, 120],
            in_array($kind, ['notifications.email', 'contact.email'], true) => [30, 60, 120, 240],
            default => [5, 30, 120, 300],
        };

        return $delays[min(max(0, $attempt - 1), count($delays) - 1)];
    }
}
