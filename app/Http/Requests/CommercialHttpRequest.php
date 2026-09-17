<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class CommercialHttpRequest extends FormRequest
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
            'proposal.attach_document' => ['document_id' => ['required', 'string', 'uuid']],
            'discovery.create','discovery.update' => ['summary' => ['required', 'string', 'max:20000'], 'internal_notes' => ['sometimes', 'nullable', 'string', 'max:10000']],
            'discovery.requirements' => ['requirements' => ['present', 'array', 'max:100']],
            'proposal.create','proposal.update' => [
                'discovery_revision_id' => ['required', 'string', 'uuid'], 'scope_summary' => ['required', 'string', 'max:20000'],
                'timeline' => ['required', 'string', 'max:5000'], 'commercial_notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'pricing_mode' => ['required', 'string', 'in:fixed,items'], 'amount' => ['required', 'string', 'max:24'],
                'currency' => ['required', 'string', 'in:USD,LYD'], 'valid_until' => ['required', 'string', 'max:30'],
                'items' => ['present', 'array', 'max:100'], 'deliverables' => ['required', 'array', 'min:1', 'max:50']],
            'proposal.withdraw','proposal.supersede','proposal.rescind' => ['reason' => ['required', 'string', 'max:5000']],
            'proposal.decline' => ['reason' => ['sometimes', 'nullable', 'string', 'min:1', 'max:5000']],
            'proposal.list','discovery.list' => ['after' => ['sometimes', 'integer', 'min:0', 'max:2147483647'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']],
            default => [],
        };
    }

    protected function prepareForValidation(): void
    {
        if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
            throw ValidationException::withMessages(['input' => 'Unsupported fields.']);
        }
        if ($this->isMethod('GET')) {
            foreach (['after', 'limit'] as $field) {
                $value = $this->input($field);
                if (is_string($value) && preg_match('/\A[0-9]{1,10}\z/D', $value) === 1) {
                    $this->merge([$field => (int) $value]);
                }
            }
        }
    }
}
