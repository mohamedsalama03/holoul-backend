<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Infrastructure\Clock\DatabaseClock;
use App\Modules\Projects\Contracts\ProjectStaffReader;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ManageProjectTeam
{
    public function __construct(private ProjectStore $store, private ProjectStaffReader $staff) {}

    public function initialize(ProjectActor $actor, Project $project, string $managerId, string $correlation): void
    {
        if ($project->lock_version !== 1 || $project->state !== 'planning' || $actor->customerId !== null
            || ! in_array('projects.convert', $actor->permissions, true)
            || DB::table('project_members')->where('project_id', $project->id)->exists()) {
            throw new HttpException(409);
        }
        $this->staff->requireAssignable($managerId, 'project_manager');
        $this->insert($actor, $project, $managerId, 'project_manager', $correlation);
        DB::table('project_state_changes')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
            'from_state' => null, 'to_state' => 'planning', 'from_epoch' => null, 'to_epoch' => 1,
            'actor_id' => $actor->id, 'entity_version' => 1, 'correlation_id' => $correlation]);
        $this->store->event($project, 'created', $actor, $correlation);
    }

    /** @param array<string,mixed> $input */
    public function add(ProjectActor $actor, Project $project, array $input, string $correlation): string
    {
        $this->store->staff($actor, $project, 'projects.team.manage', true);
        $this->store->active($project);
        Validator::make($input, ['staff_id' => ['required', 'uuid:7'], 'role' => ['required', 'in:project_manager,business_analyst,contributor']])->validate();
        $id = $input['staff_id'];
        $role = $input['role'];
        if (! is_string($id) || ! is_string($role)) {
            throw new HttpException(422);
        }
        $this->staff->requireAssignable($id, $role);
        if (DB::table('project_members')->where('project_id', $project->id)->where('user_id', $id)->where('active', true)->exists()) {
            throw new HttpException(409);
        }
        $this->store->changed($project);

        return $this->insert($actor, $project, $id, $role, $correlation);
    }

    public function remove(ProjectActor $actor, Project $project, string $memberId, string $correlation): string
    {
        $this->store->staff($actor, $project, 'projects.team.manage', true);
        $this->store->active($project);
        if (! Str::isUuid($memberId, 7)) {
            throw new HttpException(404);
        }
        $member = DB::table('project_members')->where('project_id', $project->id)->where('id', $memberId)->where('active', true)->lockForUpdate()->first() ?? throw new HttpException(404);
        if (($member->role === 'project_manager' && ! DB::table('project_members')->where('project_id', $project->id)
            ->where('active', true)->where('role', 'project_manager')->where('id', '<>', $memberId)->exists())
            || DB::table('milestones')->where('project_id', $project->id)->where('responsible_member_id', $memberId)->where('state', '<>', 'completed')->exists()) {
            throw new HttpException(409);
        }
        $this->store->changed($project);
        DB::table('project_members')->where('id', $memberId)->update(['active' => false, 'removed_by' => $actor->id, 'removed_at' => DatabaseClock::now()]);
        $this->history($actor, $project, $memberId, 'removed', $correlation);

        return $memberId;
    }

    private function insert(ProjectActor $actor, Project $project, string $staff, string $role, string $correlation): string
    {
        $member = (string) Str::uuid7();
        DB::table('project_members')->insert(['id' => $member, 'project_id' => $project->id, 'user_id' => $staff,
            'role' => $role, 'added_by' => $actor->id]);
        $this->history($actor, $project, $member, 'added', $correlation);

        return $member;
    }

    private function history(ProjectActor $actor, Project $project, string $member, string $event, string $correlation): void
    {
        DB::table('project_membership_history')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
            'member_id' => $member, 'event' => $event, 'actor_id' => $actor->id,
            'entity_version' => $project->lock_version, 'correlation_id' => $correlation]);
        $this->store->event($project, 'team_member_'.$event, $actor, $correlation);
    }
}
