<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mfa\Requests;

use App\Modules\Identity\Security\IdentityInput;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

final class MfaCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        IdentityInput::only($this, ['code']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'min:6', 'max:35', 'regex:/\A[0-9a-fA-F-]+\z/']];
    }

    public function validatedCode(): string
    {
        $code = $this->validated('code');

        if (! is_string($code)) {
            throw new LogicException('A validated code must be a string.');
        }

        return $code;
    }
}
