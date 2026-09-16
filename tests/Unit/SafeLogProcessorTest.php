<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Logging\SafeLogProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SafeLogProcessorTest extends TestCase
{
    public function test_private_context_exceptions_and_messages_are_removed(): void
    {
        $record = new LogRecord(new DateTimeImmutable, 'stdout', Level::Error,
            'SQL password=secret /private/document',
            ['password' => 'secret', 'document' => 'private', 'exception' => new RuntimeException('private'),
                'error_code' => 'EXECUTION_FAILED', 'attempt' => 2, 'queue' => 'default'],
            ['trace' => 'private'],
        );
        $safe = (new SafeLogProcessor)($record);
        self::assertSame('application.event', $safe->message);
        self::assertSame(['error_code' => 'EXECUTION_FAILED', 'attempt' => 2, 'queue' => 'default'], $safe->context);
        self::assertSame([], $safe->extra);
    }

    public function test_static_event_codes_and_uuid_correlation_survive(): void
    {
        $record = new LogRecord(new DateTimeImmutable, 'stdout', Level::Info, 'operation.dispatched',
            ['request_id' => '01994484-1540-7d5d-8f1a-b74980a92184', 'duration_ms' => 4],
        );
        $safe = (new SafeLogProcessor)($record);
        self::assertSame($record->message, $safe->message);
        self::assertSame($record->context, $safe->context);
    }

    public function test_correlation_keys_require_uuid_values_even_when_private_text_looks_like_a_token(): void
    {
        $record = new LogRecord(new DateTimeImmutable, 'stdout', Level::Error, 'request.failed',
            ['request_id' => 'private-secret', 'operation_id' => 'private_document'],
        );

        self::assertSame([], (new SafeLogProcessor)($record)->context);
    }
}
