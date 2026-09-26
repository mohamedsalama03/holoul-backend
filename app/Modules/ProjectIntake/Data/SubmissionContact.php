<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Data;

use App\Modules\Customers\Data\InternationalPhone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Normalizer;

final readonly class SubmissionContact
{
    public function __construct(public string $fullName, public string $email, public string $phoneE164) {}

    public static function guest(string $name, string $email, string $phone): self
    {
        $name = Normalizer::normalize(trim($name), Normalizer::FORM_C);
        $email = mb_strtolower(trim($email), 'UTF-8');
        Validator::make(['full_name' => $name, 'email' => $email], [
            'full_name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
        ])->validate();
        if (! is_string($name)) {
            throw ValidationException::withMessages(['full_name' => 'Invalid name.']);
        }

        return new self($name, $email, InternationalPhone::parse($phone)->e164);
    }
}
