<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

final class PasswordRequest extends IdentityRequest
{
    protected function allowedFields(): array
    {
        return ['password'];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:128']];
    }
}
