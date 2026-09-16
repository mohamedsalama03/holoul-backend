<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

final class NameRequest extends IdentityRequest
{
    protected function allowedFields(): array
    {
        return ['full_name'];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['full_name' => ['required', 'string', 'min:2', 'max:160', 'not_regex:/[\p{C}]/u']];
    }
}
