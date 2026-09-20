<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Projects\Actions\ManageMilestones;
use App\Modules\Projects\Actions\ManageProjectTeam;
use App\Modules\Projects\Actions\ProjectLifecycle;
use App\Modules\Projects\Actions\ProjectRead;
use App\Modules\Projects\Actions\ProjectReceipts;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Projects\Actions\PublishProjectUpdate;
use App\Modules\Projects\Contracts\ProjectStaffReader;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ProjectWorkflow
{
    public function __construct(private ProjectStore $store, private ProjectRead $reads, private ProjectReceipts $receipts,
        private ProjectLifecycle $lifecycle, private ManageProjectTeam $team, private ManageMilestones $milestones,
        private PublishProjectUpdate $updates, private ProjectStaffReader $staff) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function handle(ProjectActor $actor, string $projectId, string $id, string $operation, ?string $etag,
        ?string $key, array $input, string $correlation): array
    {
        return DB::transaction(function () use ($actor, $projectId, $id, $operation, $etag, $key, $input, $correlation): array {
            $after = is_string($input['after'] ?? null) ? $input['after'] : null;
            $limit = is_int($input['limit'] ?? null) ? $input['limit'] : 25;
            if ($operation === 'project.list') {
                return $this->reads->listing($actor, $after, $limit, is_string($input['state'] ?? null) ? $input['state'] : null);
            }
            // Candidate identities precede the Project lock; reauthorize the locked Project below.
            if ($operation === 'project.team.add' || in_array($operation, ['project.milestone.create', 'project.milestone.update'], true)) {
                $this->authorize($actor, $this->store->find($actor, $projectId, false), $operation);
                $fingerprint = $this->receipts->fingerprint($operation, $projectId, $id, $etag, $key, $input);
                if ($this->receipts->replay($actor, $operation, $projectId, $fingerprint) !== null) {
                    $this->authorize($actor, $this->store->find($actor, $projectId), $operation);
                    $replay = $this->receipts->replay($actor, $operation, $projectId, $fingerprint);
                    if ($replay !== null) {
                        return ['data' => $replay, 'project_version' => $replay['version']];
                    }
                }
                $this->lockCandidate($projectId, $operation, $input);
            }
            $project = $this->store->find($actor, $projectId);
            $this->authorize($actor, $project, $operation);
            $result = match ($operation) {
                'project.detail' => ['data' => $this->reads->detail($actor, $project, $correlation)],
                'project.milestones' => $this->reads->milestones($actor, $project, $after, $limit),
                'project.updates' => $this->reads->updates($actor, $project, $after, $limit),
                'project.activity' => $this->reads->activity($actor, $project, $after, $limit),
                'project.team' => $this->reads->team($actor, $project, $after, $limit),
                'project.evidence.list' => $this->reads->evidence($actor, $project, $after, $limit),
                default => null,
            };
            if ($result !== null) {
                return [...$result, 'project_version' => $project->lock_version];
            }
            $fingerprint = $this->receipts->fingerprint($operation, $projectId, $id, $etag, $key, $input);
            $replay = $this->receipts->replay($actor, $operation, $projectId, $fingerprint);
            if ($replay !== null) {
                return ['data' => $replay, 'project_version' => $replay['version']];
            }
            VersionPrecondition::require($etag, $projectId, $project->lock_version);
            $resultId = match ($operation) {
                'project.evidence' => $this->lifecycle->recordEvidence($actor, $project, $input, $correlation)->id,
                'project.advance' => $this->lifecycle->advance($actor, $project, $correlation)->id,
                'project.hold' => $this->lifecycle->hold($actor, $project, $input, $correlation)->id,
                'project.resume' => $this->lifecycle->resume($actor, $project, $input, $correlation)->id,
                'project.fail' => $this->lifecycle->fail($actor, $project, $input, $correlation)->id,
                'project.cancel' => $this->lifecycle->cancel($actor, $project, $input, $correlation)->id,
                'project.confirm' => $this->lifecycle->confirmCompletion($actor, $project, $correlation)->id,
                'project.team.add' => $this->team->add($actor, $project, $input, $correlation),
                'project.team.remove' => $this->team->remove($actor, $project, $id, $correlation),
                'project.milestone.create' => $this->milestones->create($actor, $project, $input, $correlation)->id,
                'project.milestone.update' => $this->milestones->update($actor, $project, $id, $input, $correlation)->id,
                'project.milestone.start', 'project.milestone.delay', 'project.milestone.complete' => $this->milestones->transition($actor, $project, $id, substr($operation, 18), $input, $correlation)->id,
                'project.update.publish' => $this->updates->handle($actor, $project, $input, $correlation),
                default => throw new HttpException(404),
            };

            return ['data' => $this->receipts->record($actor, $operation, $projectId, $fingerprint, $project, $resultId), 'project_version' => $project->lock_version];
        }, 2);
    }

    private function authorize(ProjectActor $actor, Project $project, string $operation): void
    {
        if (in_array($operation, ['project.detail', 'project.milestones', 'project.updates'], true)) {
            if ($actor->customerId !== null) {
                $this->store->owner($actor, $project, 'projects.self.read');
            } else {
                $this->store->staff($actor, $project, 'projects.read');
            }

            return;
        }
        if ($operation === 'project.confirm') {
            $this->store->owner($actor, $project, 'projects.self.confirm', true);

            return;
        }
        $permission = match ($operation) {
            'project.activity', 'project.team', 'project.evidence.list' => 'projects.read',
            'project.evidence' => 'projects.manage',
            'project.advance', 'project.hold', 'project.resume', 'project.fail', 'project.cancel' => 'projects.transition',
            'project.team.add', 'project.team.remove' => 'projects.team.manage',
            'project.milestone.create', 'project.milestone.update', 'project.milestone.start', 'project.milestone.delay', 'project.milestone.complete' => 'projects.milestones.manage',
            'project.update.publish' => 'projects.updates.publish',
            default => throw new HttpException(404),
        };
        $this->store->staff($actor, $project, $permission, in_array($permission, ['projects.transition', 'projects.team.manage'], true) || ($operation === 'project.evidence' && $project->state === 'planning'));
    }

    /** @param array<string,mixed> $input */
    private function lockCandidate(string $projectId, string $operation, array $input): void
    {
        if ($operation === 'project.team.add') {
            $this->staff->requireAssignable(is_string($input['staff_id'] ?? null) ? $input['staff_id'] : '', is_string($input['role'] ?? null) ? $input['role'] : '');
        } elseif (isset($input['responsible_member_id'])) {
            $id = $input['responsible_member_id'];
            if (! is_string($id) || ! Str::isUuid($id, 7)) {
                throw new HttpException(422);
            }
            $userId = DB::table('project_members')->where('id', $id)->where('project_id', $projectId)->where('active', true)->value('user_id');
            if (! is_string($userId)) {
                throw new HttpException(404);
            }
            $this->staff->requireAssignable($userId, 'contributor');
        }
    }
}
