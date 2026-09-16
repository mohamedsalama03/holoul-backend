<?php

declare(strict_types=1);

namespace App\Infrastructure\Money;

enum Currency: string
{
    case USD = 'USD';
    case LYD = 'LYD';

    public function exponent(): int
    {
        return match ($this) {
            self::USD => 2,
            self::LYD => 3,
        };
    }
}
