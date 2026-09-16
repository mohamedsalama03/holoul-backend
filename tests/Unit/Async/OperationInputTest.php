<?php

declare(strict_types=1);

namespace Tests\Unit\Async;

use App\Infrastructure\Async\OperationPublisher;
use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Async\PermanentOperationFailure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationInputTest extends TestCase
{
    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function unsafeInputs(): iterable
    {
        yield 'unbounded kind' => [str_repeat('a', 81), 'key', []];
        yield 'empty identity' => ['test.operation', '', []];
        yield 'unbounded identity' => ['test.operation', str_repeat('a', 256), []];
        yield 'secret payload' => ['test.operation', 'key', ['api_key' => 'secret']];
        yield 'raw content' => ['test.operation', 'key', ['document_id' => 'raw document text']];
        yield 'uuid v4 reference' => ['test.operation', 'key', ['document_id' => '550e8400-e29b-41d4-a716-446655440000']];
    }

    /** @param array<string, string> $references */
    #[DataProvider('unsafeInputs')]
    public function test_payloads_reject_raw_content_and_unbounded_identity(string $kind, string $key, array $references): void
    {
        $recorder = new OperationRecorder(new OperationPublisher);
        $this->expectException(InvalidArgumentException::class);
        $recorder->record($kind, $key, $references);
    }

    public function test_terminal_failure_does_not_accept_arbitrary_exception_text(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PermanentOperationFailure('Provider body: secret-value');
    }
}
