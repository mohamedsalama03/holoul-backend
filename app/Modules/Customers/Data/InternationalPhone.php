<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final readonly class InternationalPhone
{
    private function __construct(public string $e164, public string $display) {}

    public static function parse(string $phone, ?string $display = null): self
    {
        $phone = trim($phone);
        $display = trim($display ?? $phone);
        $e164 = self::normalize($phone);

        if (self::normalize($display) !== $e164) {
            throw ValidationException::withMessages(['phone' => 'The phone display must identify the same number.']);
        }

        return new self($e164, $display);
    }

    private static function normalize(string $phone): string
    {
        if (strlen($phone) > 64 || preg_match('/\A\+[0-9 ().-]+\z/', $phone) !== 1) {
            throw ValidationException::withMessages(['phone' => 'An international phone number is required.']);
        }

        $library = PhoneNumberUtil::getInstance();

        try {
            // A leading + is mandatory. Never guess a country from an account or server locale.
            $parsed = $library->parse($phone, null);
        } catch (NumberParseException) {
            throw ValidationException::withMessages(['phone' => 'The phone number is invalid.']);
        }

        if (! $library->isValidNumber($parsed)) {
            throw ValidationException::withMessages(['phone' => 'The phone number is invalid.']);
        }

        return $library->format($parsed, PhoneNumberFormat::E164);
    }
}
