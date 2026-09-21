<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Contracts\ProjectStaffReader;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Events\ProjectChanged;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ManageMilestones
{
    public function __construct(private ProjectStore $store, private ProjectStaffReader $staff) {}

    /** @param array<string,mixed> $input */
    public function create(ProjectActor $actor, Project $project, array $input, string $correlation): Milestone
    {
        $this->authorize($actor, $project);
        $values = $this->values($project, $input);
        $this->store->changed($project);
        $milestone = Milestone::query()->forceCreate([...$values, 'project_id' => $project->id, 'state' => 'upcoming',
            'lock_version' => 1, 'created_by' => $actor->id]);
        $this->history($actor, $project, $milestone, 'created', null, $correlation);

        return $milestone;
    }

    /** @param array<string,mixed> $input */
    public function update(ProjectActor $actor, Project $project, string $id, array $input, string $correlation): Milestone
    {
        $this->authorize($actor, $project);
        $milestone = $this->find($project, $id);
        $values = $this->values($project, $input, $id);
        if ($milestone->state === 'completed') {
            throw new HttpException(409);
        }
        $this->store->changed($project);
        $milestone->forceFill([...$values, 'lock_version' => $milestone->lock_version + 1])->save();
        $this->history($actor, $project, $milestone, 'updated', $milestone->state, $correlation);

        return $milestone;
    }

    /** @param array<string,mixed> $input */
    public function transition(ProjectActor $actor, Project $project, string $id, string $command, array $input, string $correlation): Milestone
    {
        $this->authorize($actor, $project);
        $milestone = $this->find($project, $id);
        $from = $milestone->state;
        $next = match ($command) {
            'start' => in_array($from, ['upcoming', 'delayed'], true) ? 'in_progress' : null,
            'delay' => in_array($from, ['upcoming', 'in_progress'], true) ? 'delayed' : null,
            'complete' => $from === 'in_progress' ? 'completed' : null,
            default => null,
        };
        if ($next === null) {
            throw new HttpException(409);
        }
        $reason = null;
        if ($command === 'delay') {
            Validator::make($input, ['reason' => ['required', 'string', 'max:5000']])->validate();
            $reason = is_string($input['reason']) ? $input['reason'] : throw new HttpException(422);
        }
        $this->store->changed($project);
        $milestone->forceFill(['state' => $next, 'lock_version' => $milestone->lock_version + 1])->save();
        $this->history($actor, $project, $milestone, match ($command) {
            'start' => 'started', 'delay' => 'delayed', default => 'completed'
        }, $from, $correlation, $reason);

        return $milestone;
    }

    private function find(Project $project, string $id): Milestone
    {
        if (! Str::isUuid($id, 7)) {
            throw new HttpException(404);
        }

        return Milestone::query()->where('project_id', $project->id)->whereKey($id)->lockForUpdate()->first() ?? throw new HttpException(404);
    }

    private function authorize(ProjectActor $actor, Project $project): void
    {
        $this->store->staff($actor, $project, 'projects.milestones.manage');
        $this->store->active($project);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function values(Project $project, array $input, ?string $id = null): array
    {
        $input['description'] ??= '';
        $input['due_date'] ??= null;
        $input['responsible_member_id'] ??= null;
        Validator::make($input, ['name' => ['required', 'string', 'max:200'], 'description' => ['present', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date_format:Y-m-d'], 'display_order' => ['required', 'integer', 'min:1', 'max:1000'],
            'responsible_member_id' => ['nullable', 'uuid:7'], 'customer_visible' => ['required', 'boolean']])->validate();
        if (! is_int($input['display_order']) || ! is_bool($input['customer_visible'])) {
            throw new HttpException(422);
        }
        $occupied = DB::table('milestones')->where('project_id', $project->id)->where('display_order', $input['display_order']);
        if ($id !== null) {
            $occupied->where('id', '<>', $id);
        }
        if ($occupied->exists()) {
            throw new HttpException(409);
        }
        $responsible = $input['responsible_member_id'];
        if ($responsible !== null) {
            if (! is_string($responsible)) {
                throw new HttpException(422);
            }
            $member = DB::table('project_members')->where('project_id', $project->id)->where('id', $responsible)->where('active', true)->first() ?? throw new HttpException(404);
            if (! is_string($member->user_id)) {
                throw new HttpException(409);
            }
            $this->staff->requireAssignable($member->user_id, 'contributor');
        }

        return array_intersect_key($input, array_flip(['name', 'description', 'due_date', 'display_order', 'responsible_member_id', 'customer_visible']));
    }

    private function history(ProjectActor $actor, Project $project, Milestone $milestone, string $event, ?string $from, string $correlation, ?string $reason = null): void
    {
        DB::table('milestone_changes')->insert(['id' => (string) Str::uuid7(), 'milestone_id' => $milestone->id,
            'project_id' => $project->id, 'event' => $event, 'from_state' => $from, 'to_state' => $milestone->state,
            'actor_id' => $actor->id, 'milestone_version' => $milestone->lock_version, 'entity_version' => $project->lock_version,
            'reason' => $reason, 'correlation_id' => $correlation]);
        $this->store->event($project, 'milestone_'.$event, $actor, $correlation);
        if ($milestone->customer_visible && in_array($event, ['started', 'delayed', 'completed'], true)) {
            Event::dispatch(new ProjectChanged($project->id, 'milestone_updated', $project->lock_version, $project->customer_user_id, $correlation));
        }
    }
}
