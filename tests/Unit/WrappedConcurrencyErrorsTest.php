<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Database\WrappedConcurrencyErrors;
use Illuminate\Database\DeadlockException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WrappedConcurrencyErrorsTest extends TestCase
{
    public function test_nested_framework_wrapping_preserves_serialization_detection_without_broadening_other_failures(): void
    {
        $detector = new WrappedConcurrencyErrors;
        $serialization = new PDOException('could not serialize access due to concurrent update', 40001);
        self::assertTrue($detector->causedByConcurrencyError($serialization));
        self::assertTrue($detector->causedByConcurrencyError(new DeadlockException('wrapped', 0, new DeadlockException('wrapped', 0, $serialization))));
        self::assertTrue($detector->causedByConcurrencyError(new PDOException('deadlock detected', 0)));
        self::assertFalse($detector->causedByConcurrencyError(new DeadlockException('wrapped', 0, new PDOException('constraint failure', 23514))));
        self::assertFalse($detector->causedByConcurrencyError(new RuntimeException('ordinary failure', 0, $serialization)));
    }
}
