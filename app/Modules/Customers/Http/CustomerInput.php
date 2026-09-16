<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CustomerInput
{
    public static function phone(Request $request): string
    {
        self::allowOnly($request, ['phone']);
        $request->validate(['phone' => ['required', 'string', 'max:64']]);

        return $request->string('phone')->toString();
    }

    public static function search(Request $request): string
    {
        self::allowOnly($request, ['search']);
        $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:64']]);

        return $request->string('search')->toString();
    }

    /** @param list<string> $allowed */
    private static function allowOnly(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported input fields.']);
        }
    }
}
