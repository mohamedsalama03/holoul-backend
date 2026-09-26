<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class AdminIntegrationRequest extends FormRequest
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
        $page = ['after' => ['sometimes', 'string', 'uuid:7'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:50']];
        $search = ['q' => ['sometimes', 'string', 'min:2', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/']];

        return match ($this->operation()) {
            'customers.list' => [...$page, ...$search, 'status' => ['sometimes', 'string', 'in:active,disabled'], 'email_verified' => ['sometimes', 'string', 'in:true,false']],
            'customers.requests', 'customers.projects' => $page,
            'intake.eligible' => [...$page, ...$search],
            'projects.eligible' => [...$page, ...$search, 'role' => ['required', 'string', 'in:project_manager,business_analyst,contributor']],
            default => [],
        };
    }

    protected function prepareForValidation(): void
    {
        if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
        }
        $limit = $this->input('limit');
        if (is_string($limit) && preg_match('/\A[0-9]{1,3}\z/D', $limit) === 1) {
            $this->merge(['limit' => (int) $limit]);
        }
        if ($this->exists('limit') && ! is_int($this->input('limit'))) {
            throw ValidationException::withMessages(['limit' => 'An integer is required.']);
        }
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->string('q')->toString())]);
        }
    }
}
