<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Security\IdentityInput;

final class ChangePasswordRequest extends IdentityRequest
{
    protected function allowedFields(): array
    {
        return ['current_password', 'password', 'password_confirmation'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:128'], 'password' => [...IdentityInput::passwordRules(), 'confirmed']];
    }
}
