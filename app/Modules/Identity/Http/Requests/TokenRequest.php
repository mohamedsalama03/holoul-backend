<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

final class TokenRequest extends IdentityRequest
{
    protected function allowedFields(): array
    {
        return ['token'];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']];
    }
}
