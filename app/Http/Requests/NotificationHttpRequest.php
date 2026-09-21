<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class NotificationHttpRequest extends FormRequest
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
        $pagination = ['after' => ['sometimes', 'uuid:7'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']];

        return match ($this->operation()) {
            'list' => $pagination,
            'deliveries' => [...$pagination, 'state' => ['sometimes', 'in:pending,processing,accepted,failed,uncertain,suppressed']],
            'preferences_update' => ['workflow_email' => ['required', 'boolean']],
            'replay' => ['reason' => ['required', 'string', 'max:1000'], 'resolution' => ['required', 'in:retry_failure,confirmed_not_accepted']],
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
