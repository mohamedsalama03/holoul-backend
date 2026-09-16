<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Security\IdentityInput;

final class ResetRequest extends IdentityRequest
{
    protected function allowedFields(): array
    {
        return ['token', 'password', 'password_confirmation'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'password' => [...IdentityInput::passwordRules(), 'confirmed']];
    }
}
