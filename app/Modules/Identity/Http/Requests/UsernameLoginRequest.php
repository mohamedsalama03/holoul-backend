<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

final class UsernameLoginRequest extends IdentityRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (is_string($this->input('username'))) {
            $this->merge(['username' => strtolower(trim($this->string('username')->toString()))]);
        }
    }

    protected function allowedFields(): array
    {
        return ['username', 'password'];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['username' => ['required', 'string', 'max:40', 'regex:/\A[a-zA-Z][a-zA-Z0-9._-]{2,39}\z/D'],
            'password' => ['required', 'string', 'max:128']];
    }
}
