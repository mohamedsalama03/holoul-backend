<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Modules\Identity\Security\IdentityInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Normalizer;

final class ContactRequest extends FormRequest
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
        return match ($this->operation()) {
            'create' => ['full_name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\p{C}]/u'],
                'email' => ['required', 'string', 'email:rfc', 'max:254', 'regex:/\A[\x21-\x7e]+\z/'],
                'phone' => ['required', 'string', 'max:64'], 'company' => ['nullable', 'string', 'min:1', 'max:160', 'not_regex:/[\p{C}]/u'],
                'message' => ['required', 'string', 'min:10', 'max:5000', 'not_regex:/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u']],
            'list' => ['status' => ['sometimes', 'string', 'in:received,in_progress,resolved,spam,redacted'],
                'limit' => ['sometimes', 'integer', 'min:1', 'max:50'], 'cursor' => ['sometimes', 'string', 'max:512']],
            'update' => ['status' => ['required', 'string', 'in:received,in_progress,resolved,spam']],
            default => [],
        };
    }

    protected function prepareForValidation(): void
    {
        if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
        }
        foreach (['full_name', 'company', 'message'] as $name) {
            $value = $this->input($name);
            if (is_string($value)) {
                $value = Normalizer::normalize(str_replace(["\r\n", "\r"], "\n", $value), Normalizer::FORM_C);
                if (! is_string($value)) {
                    throw ValidationException::withMessages([$name => 'Invalid text.']);
                }
                $value = trim($value);
                if ($name !== 'message') {
                    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
                }
                $this->merge([$name => $name === 'company' && $value === '' ? null : $value]);
            }
        }
        if (is_string($this->input('email'))) {
            $this->merge(['email' => IdentityInput::email($this->string('email')->toString())]);
        }
        $limit = $this->input('limit');
        if (is_string($limit) && preg_match('/\A[0-9]{1,2}\z/D', $limit) === 1) {
            $this->merge(['limit' => (int) $limit]);
        }
        if ($this->exists('limit') && ! is_int($this->input('limit'))) {
            throw ValidationException::withMessages(['limit' => 'Invalid limit.']);
        }
    }
}
