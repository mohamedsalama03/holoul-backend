<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class ProjectHttpRequest extends FormRequest
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
        $pagination = ['after' => ['sometimes', 'string', 'uuid:7'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']];

        return match ($this->operation()) {
            'project.list' => [...$pagination, 'state' => ['sometimes', 'string', 'in:planning,design,development,testing,deployment,on_hold,completed,cancelled']],
            'project.milestones', 'project.updates', 'project.activity', 'project.team', 'project.evidence.list' => $pagination,
            'project.evidence' => ['kind' => ['required', 'string', 'in:plan_approved,design_approved,delivery_candidate,qa_passed,deployment_succeeded'], 'summary' => ['required', 'string', 'max:5000']],
            'project.hold', 'project.fail', 'project.cancel' => ['reason' => ['required', 'string', 'max:5000'], 'customer_communication' => ['required', 'string', 'max:5000']],
            'project.resume' => ['reason' => ['required', 'string', 'max:5000'], 'conditions' => ['required', 'string', 'max:5000']],
            'project.team.add' => ['staff_id' => ['required', 'string', 'uuid:7'], 'role' => ['required', 'string', 'in:project_manager,business_analyst,contributor']],
            'project.milestone.create', 'project.milestone.update' => ['name' => ['required', 'string', 'max:200'], 'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'display_order' => ['required', 'integer', 'min:1', 'max:1000'],
                'responsible_member_id' => ['sometimes', 'nullable', 'string', 'uuid:7'], 'customer_visible' => ['required', 'boolean']],
            'project.milestone.delay' => ['reason' => ['required', 'string', 'max:5000']],
            'project.update.publish' => ['content' => ['required', 'string', 'max:10000']],
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
