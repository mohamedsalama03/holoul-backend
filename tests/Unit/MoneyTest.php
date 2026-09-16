<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, string}> */
    public static function exactAmounts(): iterable
    {
        yield 'USD zero' => ['0', 'USD', 0, '0.00'];
        yield 'LYD zero' => ['0.000', 'LYD', 0, '0.000'];
        yield 'USD whole' => ['12', 'USD', 1200, '12.00'];
        yield 'USD partial fraction' => ['12.3', 'USD', 1230, '12.30'];
        yield 'USD full fraction' => ['12.34', 'USD', 1234, '12.34'];
        yield 'LYD full fraction' => ['12.345', 'LYD', 12345, '12.345'];
        yield 'LYD smallest unit' => ['0.001', 'LYD', 1, '0.001'];
        yield 'USD max' => ['92233720368547758.07', 'USD', PHP_INT_MAX, '92233720368547758.07'];
        yield 'LYD max' => ['9223372036854775.807', 'LYD', PHP_INT_MAX, '9223372036854775.807'];
        yield 'beyond floating point exactness' => ['90071992547409.93', 'USD', 9007199254740993, '90071992547409.93'];
    }

    #[DataProvider('exactAmounts')]
    public function test_exact_decimal_round_trip(string $input, string $currency, int $minor, string $canonical): void
    {
        $amount = Money::parse($input, $currency);
        self::assertSame($minor, $amount->minorUnits);
        self::assertSame($currency, $amount->currency);
        self::assertSame($canonical, $amount->decimal());
        self::assertSame($canonical, Money::fromMinorUnits($minor, $currency)->decimal());
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidAmounts(): iterable
    {
        foreach (['-1', '-0', '+1', '1e2', 'NaN', 'INF', '1,00', ' 1', '1 ', '01', '.5', '1.', '', '١٢.٣٤', '1.000', '0.000', '92233720368547758.08', '9223372036854775807', str_repeat('9', 100)] as $input) {
            yield 'USD '.$input => [$input, 'USD'];
        }
        yield 'LYD excessive precision' => ['1.0000', 'LYD'];
        yield 'LYD overflow one unit' => ['9223372036854775.808', 'LYD'];
        yield 'unsupported code' => ['1', 'EUR'];
        yield 'lowercase currency' => ['1', 'usd'];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_input_never_rounds_or_overflows(string $input, string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse($input, $currency);
    }

    public function test_negative_stored_minor_units_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromMinorUnits(-1, 'USD');
    }
}
