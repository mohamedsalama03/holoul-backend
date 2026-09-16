<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Security\IdentityInput;
use Illuminate\Foundation\Http\FormRequest;

abstract class IdentityRequest extends FormRequest
{
    /** @return list<string> */
    abstract protected function allowedFields(): array;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        IdentityInput::only($this, $this->allowedFields());
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->attributes->set('email_display', trim($this->string('email')->toString()));
            $this->merge(['email' => IdentityInput::email($this->string('email')->toString())]);
        }
        if ($this->has('full_name') && is_string($this->input('full_name'))) {
            $this->merge(['full_name' => IdentityInput::name($this->string('full_name')->toString())]);
        }
    }
}
