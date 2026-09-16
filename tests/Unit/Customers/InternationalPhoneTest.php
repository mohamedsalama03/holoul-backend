<?php

declare(strict_types=1);

namespace Tests\Unit\Customers;

use App\Modules\Customers\Data\InternationalPhone;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InternationalPhoneTest extends TestCase
{
    public function test_international_phone_is_normalized_without_losing_its_display(): void
    {
        $phone = InternationalPhone::parse('+1 (202) 555-0123');

        self::assertSame('+12025550123', $phone->e164);
        self::assertSame('+1 (202) 555-0123', $phone->display);
    }

    #[DataProvider('invalidPhones')]
    public function test_local_ambiguous_invalid_extended_and_unbounded_numbers_are_rejected(string $phone): void
    {
        $this->expectException(ValidationException::class);
        InternationalPhone::parse($phone);
    }

    public function test_display_cannot_refer_to_a_different_number(): void
    {
        $this->expectException(ValidationException::class);
        InternationalPhone::parse('+12025550123', '+442079460018');
    }

    /** @return array<string, array{string}> */
    public static function invalidPhones(): array
    {
        return [
            'local' => ['2025550123'], 'international access prefix' => ['0012025550123'],
            'invalid country code' => ['+9992025550123'], 'extension' => ['+12025550123 ext 5'],
            'short' => ['+1'], 'control character' => ["+12025550123\nsecret"],
            'unbounded' => ['+'.str_repeat('1', 65)],
        ];
    }
}
