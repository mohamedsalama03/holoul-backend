<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use App\Modules\Proposals\Contracts\AcceptedProposalReader;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ProjectRead
{
    public function __construct(private ProjectStore $store, private AcceptedProposalReader $baseline) {}

    /** @return array<string,mixed> */
    public function listing(ProjectActor $actor, ?string $after, int $limit, ?string $state = null): array
    {
        $this->bounds($after, $limit);
        $query = $this->store->scoped($actor);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        if ($state !== null) {
            if (! in_array($state, ['planning', 'design', 'development', 'testing', 'deployment', 'on_hold', 'completed', 'cancelled'], true)) {
                throw new HttpException(422);
            }
            $query->where('state', $state);
        }
        $rows = $query->orderBy('id')->limit($limit + 1)->get(['id', 'reference', 'name', 'state', 'lock_version', 'created_at']);

        return ['data' => $rows->take($limit)->toArray(), 'meta' => ['next_after' => $rows->count() > $limit ? $rows[$limit - 1]?->id : null]];
    }

    /** @return array<string,mixed> */
    public function detail(ProjectActor $actor, Project $project, string $correlation): array
    {
        $this->authorize($actor, $project);
        if ($actor->customerId === null) {
            $this->store->event($project, 'staff_viewed', $actor, $correlation);
        }
        $deploymentEvidence = DB::table('project_phase_evidence')->where('project_id', $project->id)->where('phase_epoch', $project->phase_epoch)
            ->where('kind', 'deployment_succeeded')->orderByDesc('entity_version')->value('id');

        return [...$project->only(['id', 'reference', 'name', 'state', 'previous_phase', 'lock_version', 'created_at']),
            'source_request_id' => $project->source_request_id,
            'accepted_baseline' => $this->baseline->baseline($project->accepted_proposal_id, $project->accepted_decision_id, $project->source_request_id, $project->customer_id),
            'deployment_evidence_recorded' => $project->state === 'deployment' && $deploymentEvidence !== null,
            'completion_confirmed' => $project->state === 'completed' || ($deploymentEvidence !== null && DB::table('project_completion_confirmations')->where('project_id', $project->id)->where('deployment_evidence_id', $deploymentEvidence)->exists())];
    }

    /** @return array<string,mixed> */
    public function milestones(ProjectActor $actor, Project $project, ?string $after, int $limit): array
    {
        $this->authorize($actor, $project);
        $this->bounds($after, $limit);
        $query = DB::table('milestones')->where('project_id', $project->id);
        $fields = ['id', 'name', 'description', 'state', 'due_date', 'display_order', 'lock_version'];
        if ($actor->customerId !== null) {
            $query->where('customer_visible', true);
        } else {
            $fields = [...$fields, 'responsible_member_id', 'customer_visible'];
        }

        if ($after !== null) {
            $position = (clone $query)->where('id', $after)->value('display_order');
            if (! is_int($position)) {
                throw new HttpException(404);
            }
            $query->where('display_order', '>', $position);
        }
        $rows = $query->orderBy('display_order')->limit($limit + 1)->get($fields);

        return ['data' => $rows->take($limit)->all(), 'meta' => ['next_after' => $rows->count() > $limit ? $rows[$limit - 1]?->id : null]];
    }

    /** @return array<string,mixed> */
    public function updates(ProjectActor $actor, Project $project, ?string $after, int $limit): array
    {
        $this->authorize($actor, $project);

        return $this->page(DB::table('project_updates')->where('project_id', $project->id), ['id', 'author_id', 'content', 'published_at'], $after, $limit);
    }

    /** @return array<string,mixed> */
    public function activity(ProjectActor $actor, Project $project, ?string $after, int $limit): array
    {
        $this->store->staff($actor, $project, 'projects.read');

        return $this->page(DB::table('project_activity')->where('project_id', $project->id), ['id', 'event', 'actor_id', 'entity_version', 'reason', 'correlation_id', 'created_at'], $after, $limit);
    }

    /** @return array<string,mixed> */
    public function team(ProjectActor $actor, Project $project, ?string $after, int $limit): array
    {
        $this->store->staff($actor, $project, 'projects.read');

        return $this->page(DB::table('project_members')->where('project_id', $project->id), ['id', 'user_id', 'role', 'active', 'added_at', 'removed_at'], $after, $limit);
    }

    /** @return array<string,mixed> */
    public function evidence(ProjectActor $actor, Project $project, ?string $after, int $limit): array
    {
        $this->store->staff($actor, $project, 'projects.read');

        return $this->page(DB::table('project_phase_evidence')->where('project_id', $project->id), ['id', 'phase', 'phase_epoch', 'kind', 'summary', 'recorded_by', 'entity_version', 'created_at'], $after, $limit);
    }

    private function authorize(ProjectActor $actor, Project $project): void
    {
        if ($actor->customerId !== null) {
            $this->store->owner($actor, $project, 'projects.self.read');
        } else {
            $this->store->staff($actor, $project, 'projects.read');
        }
    }

    /** @param list<string> $fields
     * @return array<string,mixed>
     */
    private function page(Builder $query, array $fields, ?string $after, int $limit): array
    {
        $this->bounds($after, $limit);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $rows = $query->orderBy('id')->limit($limit + 1)->get($fields);

        return ['data' => $rows->take($limit)->all(), 'meta' => ['next_after' => $rows->count() > $limit ? $rows[$limit - 1]?->id : null]];
    }

    private function bounds(?string $after, int $limit): void
    {
        if (($after !== null && ! Str::isUuid($after, 7)) || $limit < 1 || $limit > 100) {
            throw new HttpException(422);
        }
    }
}
