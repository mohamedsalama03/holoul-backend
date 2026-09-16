<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Security\IdentityInput;

final class RegisterRequest extends IdentityRequest
{
    protected function allowedFields(): array
    {
        return ['full_name', 'email', 'password', 'password_confirmation', 'phone'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:160', 'not_regex:/[\p{C}]/u'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'regex:/\A[\x21-\x7e]+\z/'],
            'password' => [...IdentityInput::passwordRules(), 'confirmed'],
            'phone' => ['required', 'string', 'max:64'],
        ];
    }
}
