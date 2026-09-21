<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class AIHttpRequest extends FormRequest
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
        $parent = ['parent_type' => ['required', 'string', 'in:request,project'], 'parent_id' => ['required', 'string', 'uuid:7']];

        return match ($this->operation()) {
            'create' => [...$parent, 'purpose' => ['required', 'string', 'in:improve_description,suggest_category,analyze_document,extract_requirements,missing_information'],
                'document_id' => ['sometimes', 'nullable', 'string', 'uuid:7'], 'consent' => ['required', 'boolean', 'accepted']],
            'list' => [...$parent, 'after' => ['sometimes', 'string', 'uuid:7'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']],
            'apply' => ['discovery_revision_id' => ['sometimes', 'string', 'uuid:7']],
            default => [],
        };
    }

    protected function prepareForValidation(): void
    {
        if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
        }
        $limit = $this->input('limit');
        if ($this->isMethod('GET') && is_string($limit) && preg_match('/\A[0-9]{1,3}\z/D', $limit) === 1) {
            $this->merge(['limit' => (int) $limit]);
        }
    }
}
