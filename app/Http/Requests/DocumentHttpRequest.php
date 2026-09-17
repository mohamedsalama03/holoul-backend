<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class DocumentHttpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function operation(): string
    {
        $value = $this->route('operation');

        return is_string($value) ? $value : '';
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return $this->operation() === 'reserve' ? [
            'filename' => ['required', 'string', 'max:240'],
            'bytes' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
        ] : [];
    }

    protected function prepareForValidation(): void
    {
        if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
        }
        if ($this->operation() === 'reserve' && ! is_int($this->input('bytes'))) {
            throw ValidationException::withMessages(['bytes' => 'An integer is required.']);
        }
    }
}
