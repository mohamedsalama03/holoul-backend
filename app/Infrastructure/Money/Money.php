<?php

declare(strict_types=1);

namespace App\Infrastructure\Money;

use InvalidArgumentException;

/** Non-negative PostgreSQL BIGINT minor units; no floating-point conversions. */
final readonly class Money
{
    private const MAX_MINOR_UNITS = '9223372036854775807';

    private function __construct(public int $minorUnits, public string $currency, public int $exponent) {}

    public static function parse(string $decimal, string $currency): self
    {
        $unit = Currency::tryFrom($currency) ?? throw new InvalidArgumentException('Unsupported currency.');
        $exponent = $unit->exponent();

        if (strlen($decimal) > 24 || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,'.$exponent.'})?\z/', $decimal) !== 1) {
            throw new InvalidArgumentException('Amount must be a non-negative decimal string with supported precision.');
        }

        $parts = explode('.', $decimal, 2);
        $minor = ltrim($parts[0].str_pad($parts[1] ?? '', $exponent, '0'), '0');
        $minor = $minor === '' ? '0' : $minor;

        if (strlen($minor) > strlen(self::MAX_MINOR_UNITS)
            || (strlen($minor) === strlen(self::MAX_MINOR_UNITS) && strcmp($minor, self::MAX_MINOR_UNITS) > 0)) {
            throw new InvalidArgumentException('Amount exceeds the supported integer range.');
        }

        return new self((int) $minor, $unit->value, $exponent);
    }

    public static function fromMinorUnits(int $minorUnits, string $currency): self
    {
        $unit = Currency::tryFrom($currency) ?? throw new InvalidArgumentException('Unsupported currency.');

        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Minor units must be non-negative.');
        }

        return new self($minorUnits, $unit->value, $unit->exponent());
    }

    public function decimal(): string
    {
        $digits = str_pad((string) $this->minorUnits, $this->exponent + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$this->exponent).'.'.substr($digits, -$this->exponent);
    }
}
