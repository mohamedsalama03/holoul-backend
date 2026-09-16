<?php

declare(strict_types=1);

namespace App\Modules\Identity\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Normalizer;

final class IdentityInput
{
    public static function name(string $name): string
    {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);

        return trim(preg_replace('/\s+/u', ' ', is_string($normalized) ? $normalized : $name) ?? '');
    }

    public static function email(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /** @return list<Password|string> */
    public static function passwordRules(): array
    {
        return ['required', 'string', 'max:128', Password::min(12)->letters()->mixedCase()->numbers()];
    }

    /** @param list<string> $allowed */
    public static function only(Request $request, array $allowed): void
    {
        foreach ($request->all() as $key => $value) {
            if (! in_array($key, $allowed, true) || is_array($value)) {
                throw ValidationException::withMessages(['input' => 'Unexpected input.']);
            }
        }
    }

    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get('request_id');

        return is_string($id) ? $id : (string) Str::uuid7();
    }
}
