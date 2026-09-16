<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mfa\Requests;

use App\Modules\Identity\Security\IdentityInput;
use Illuminate\Foundation\Http\FormRequest;

final class EmptyMfaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        IdentityInput::only($this, []);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
