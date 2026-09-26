<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class ReportingHttpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function report(): string
    {
        $value = $this->route('report');

        return is_string($value) ? $value : '';
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        $window = ['from' => ['required_with:to', 'date_format:Y-m-d'], 'to' => ['required_with:from', 'date_format:Y-m-d']];
        if ($this->report() === 'audit') {
            return [...$window, 'event_type' => ['sometimes', 'string', 'max:96', 'regex:/\A[a-z][a-z0-9]*(?:[._][a-z0-9]+)*\z/D'],
                'subject_type' => ['sometimes', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9]*(?:[._][a-z0-9]+)*\z/D'],
                'actor_id' => ['sometimes', 'string', 'uuid'], 'subject_id' => ['sometimes', 'string', 'uuid'], 'request_id' => ['sometimes', 'string', 'uuid'],
                'cursor' => ['sometimes', 'string', 'max:2048'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']];
        }

        return $this->report() === 'requests' ? [...$window, 'category_id' => ['required_with:subcategory_id', 'string', 'uuid:7'],
            'subcategory_id' => ['sometimes', 'string', 'uuid:7']] : $window;
    }

    protected function prepareForValidation(): void
    {
        if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported report filters.']);
        }
        $limit = $this->input('limit');
        if (is_string($limit) && preg_match('/\A[0-9]{1,3}\z/D', $limit) === 1) {
            $this->merge(['limit' => (int) $limit]);
        }
    }
}
