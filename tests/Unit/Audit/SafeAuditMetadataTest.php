<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Modules\Audit\Data\SafeAuditMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeAuditMetadataTest extends TestCase
{
    public function test_empty_metadata_serializes_as_an_object(): void
    {
        self::assertSame('{}', (new SafeAuditMetadata)->toJson());
    }

    public function test_explicit_technical_values_are_preserved(): void
    {
        $values = [
            'outcome' => 'succeeded',
            'operation_id' => '01994f81-2222-7000-8000-000000000001',
            'attempt_number' => 1000,
            'duration_ms' => 86_400_000,
            'affected_count' => 1_000_000,
            'retryable' => false,
        ];

        self::assertSame($values, json_decode((new SafeAuditMetadata($values))->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('unsafeMetadata')]
    public function test_unknown_keys_free_text_nested_data_and_invalid_types_are_rejected(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SafeAuditMetadata($values);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unsafeMetadata(): iterable
    {
        yield 'secret' => [['token' => 'private-token']];
        yield 'raw document' => [['document' => 'private document text']];
        yield 'free text in an allowed key' => [['outcome' => 'a private message']];
        yield 'invalid identifier' => [['operation_id' => 'private-token']];
        yield 'nested object' => [['outcome' => ['token' => 'private-token']]];
        yield 'string integer' => [['attempt_number' => '1']];
        yield 'fraction' => [['duration_ms' => 1.5]];
        yield 'negative duration' => [['duration_ms' => -1]];
        yield 'unbounded duration' => [['duration_ms' => 86_400_001]];
        yield 'zero attempt' => [['attempt_number' => 0]];
        yield 'unbounded attempt' => [['attempt_number' => 1001]];
        yield 'negative count' => [['affected_count' => -1]];
        yield 'unbounded count' => [['affected_count' => 1_000_001]];
        yield 'string boolean' => [['retryable' => 'true']];
        yield 'null value' => [['outcome' => null]];
    }
}
