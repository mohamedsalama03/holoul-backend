<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Infrastructure\Clock\DatabaseClock;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use App\Modules\Proposals\Contracts\AcceptedProposal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class CreateProject
{
    public function __construct(private ManageProjectTeam $team) {}

    public function handle(ProjectActor $actor, AcceptedProposal $baseline, string $customerUserId, string $name, string $correlation): Project
    {
        if (DB::transactionLevel() === 0 || $actor->customerId !== null || ! in_array('projects.convert', $actor->permissions, true)) {
            throw new AuthorizationException;
        }
        if (Project::query()->where('source_request_id', $baseline->requestId)->exists()) {
            throw new HttpException(409);
        }
        $sequence = DB::scalar("SELECT nextval('project_reference_sequence')");
        if (! is_int($sequence)) {
            throw new HttpException(503);
        }
        $project = Project::query()->forceCreate(['source_request_id' => $baseline->requestId, 'accepted_proposal_id' => $baseline->id,
            'accepted_decision_id' => $baseline->decisionId, 'accepted_proposal_version' => $baseline->acceptedVersion,
            'customer_id' => $baseline->customerId, 'customer_user_id' => $customerUserId, 'name' => $name,
            'reference' => 'PRJ-'.DatabaseClock::now()->format('Y').'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'state' => 'planning', 'phase_epoch' => 1, 'lock_version' => 1, 'created_by' => $actor->id]);
        $this->team->initialize($actor, $project, $actor->id, $correlation);

        return $project;
    }
}
