<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\AI\Actions\AISchema;
use App\Modules\AI\Exceptions\AIProviderFailure;
use PHPUnit\Framework\TestCase;

final class AIOutputSchemaTest extends TestCase
{
    public function test_canonical_storage_overflow_is_rejected_before_the_database_writer(): void
    {
        $json = json_encode(['questions' => array_fill(0, 20, str_repeat('أ', 798))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertLessThanOrEqual(32000, strlen($json));
        $this->expectException(AIProviderFailure::class);
        (new AISchema)->validate('missing_information', $json, 32000);
    }
}
