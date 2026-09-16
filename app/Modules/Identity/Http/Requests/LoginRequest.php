<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

final class LoginRequest extends IdentityRequest
{
    protected function allowedFields(): array
    {
        return ['email', 'password'];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email:rfc', 'max:254'], 'password' => ['required', 'string', 'max:128']];
    }
}
