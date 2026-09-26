<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class GuestIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function operation(): string
    {
        $operation = $this->route('operation');

        return is_string($operation) ? $operation : '';
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return match ($this->operation()) {
            'submit' => [
                'full_name' => ['required', 'string', 'min:2', 'max:160'],
                'email' => ['required', 'string', 'max:254'],
                'phone' => ['required', 'string', 'max:64'],
                'category_id' => ['required', 'string', 'uuid'],
                'subcategory_id' => ['required', 'string', 'uuid'],
                'project_name' => ['required', 'string', 'min:1', 'max:200'],
                'project_description' => ['required', 'string', 'min:1', 'max:20000'],
                'budget_unknown' => ['required', 'boolean:strict'],
                'estimated_budget' => ['sometimes', 'nullable', 'string', 'max:32'],
                'currency' => ['sometimes', 'nullable', 'string', 'in:USD,LYD'],
            ],
            'reserve' => ['filename' => ['required', 'string', 'max:200'], 'bytes' => ['required', 'integer', 'min:1', 'max:10485760'],
                'sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D']],
            'claim' => ['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D']],
            'categories', 'subcategories' => ['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string', 'max:256']],
            default => [],
        };
    }

    protected function prepareForValidation(): void
    {
        $limit = $this->input('limit');
        if ($this->isMethod('GET') && is_string($limit) && preg_match('/\A[0-9]{1,3}\z/D', $limit) === 1) {
            $this->merge(['limit' => (int) $limit]);
        }
        if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
        }
        if ($this->operation() === 'reserve' && ! is_int($this->input('bytes'))) {
            throw ValidationException::withMessages(['bytes' => 'An integer is required.']);
        }
    }
}
