<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class IntakeHttpRequest extends FormRequest
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
            'customer.create', 'customer.update' => [
                'category_id' => ['sometimes', 'nullable', 'string', 'uuid'],
                'subcategory_id' => ['sometimes', 'nullable', 'string', 'uuid'],
                'project_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:200'],
                'project_description' => ['sometimes', 'nullable', 'string', 'min:1', 'max:20000'],
                'budget_unknown' => ['sometimes', 'nullable', 'boolean:strict'],
                'estimated_budget' => ['sometimes', 'nullable', 'string', 'max:32'],
                'currency' => ['sometimes', 'nullable', 'string', 'in:USD,LYD'],
            ],
            'staff.ask', 'staff.reject' => ['message' => ['required', 'string', 'min:1', 'max:5000']],
            'customer.response' => ['message' => ['required', 'string', 'min:1', 'max:10000']],
            'customer.withdraw' => ['message' => ['sometimes', 'nullable', 'string', 'min:1', 'max:5000']],
            'staff.assign' => ['assignee_id' => ['required', 'string', 'uuid']],
            'customer.list', 'staff.list' => [
                'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'cursor' => ['sometimes', 'string', 'max:256'],
                'sort' => ['sometimes', 'string', 'in:created_at,-created_at'],
                'state' => ['sometimes', 'string', 'in:draft,submitted,under_review,information_required,discovery,proposal,approved,converted,rejected,withdrawn'],
                'reference' => ['sometimes', 'string', 'max:40', 'regex:/^REQ-[0-9]{4}-[0-9]{5,19}$/D'],
                'category_id' => ['sometimes', 'string', 'uuid'],
                'assigned_staff_id' => ['sometimes', 'string', 'uuid'],
                'q' => ['sometimes', 'string', 'min:2', 'max:200'],
            ],
            'customer.revisions', 'staff.revisions' => [
                'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'after' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            ],
            'customer.information', 'staff.information', 'customer.history', 'staff.history', 'staff.assignments' => [
                'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'after' => ['sometimes', 'string', 'uuid'],
            ],
            'taxonomy.categories', 'taxonomy.subcategories', 'taxonomy.admin_categories', 'taxonomy.admin_subcategories' => [
                'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string', 'max:256'],
            ],
            'taxonomy.create_category', 'taxonomy.create_subcategory' => [
                'name' => ['required', 'string', 'max:160'], 'slug' => ['required', 'string', 'max:80'],
                'active' => ['required', 'boolean:strict'], 'display_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            ],
            'taxonomy.update_category', 'taxonomy.update_subcategory' => [
                'name' => ['sometimes', 'string', 'max:160'], 'active' => ['sometimes', 'boolean:strict'],
                'display_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            ],
            default => [],
        };
    }

    protected function prepareForValidation(): void
    {
        $allowed = array_keys($this->rules());
        $unknown = array_diff(array_keys($this->all()), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
        }
        if (! $this->isMethod('GET') && $this->exists('display_order') && ! is_int($this->input('display_order'))) {
            throw ValidationException::withMessages(['display_order' => 'An integer is required.']);
        }
        // GET pagination values arrive as strings. Mutations retain strict scalar types.
        if ($this->isMethod('GET')) {
            foreach (['limit', 'after'] as $field) {
                $value = $this->input($field);
                if (is_string($value) && preg_match('/\A[0-9]{1,10}\z/D', $value) === 1) {
                    $this->merge([$field => (int) $value]);
                }
            }
        }
        if ($this->exists('message') && is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->string('message')->toString())]);
        }
    }
}
