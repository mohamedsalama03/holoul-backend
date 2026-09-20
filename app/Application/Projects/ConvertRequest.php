<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\ProjectIntake\Actions\ConvertIntake;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\Projects\Actions\CreateProject;
use App\Modules\Projects\Actions\ProjectReceipts;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Proposals\Contracts\AcceptedProposalReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ConvertRequest
{
    public function __construct(private IntakeStore $intake, private ConvertIntake $conversion, private AcceptedProposalReader $proposals,
        private CreateProject $projects, private ProjectStore $store, private ProjectReceipts $receipts) {}

    /** @return array{id:string,project_id:string,reference:string,state:string,version:int} */
    public function handle(ProjectActor $actor, string $requestId, ?string $etag, ?string $key, string $correlation): array
    {
        if ($actor->customerId !== null || ! in_array('projects.convert', $actor->permissions, true)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $requestId, $etag, $key, $correlation): array {
            $intakeActor = new IntakeActor($actor->id, null, $actor->verifiedEmail, $actor->permissions);
            $request = $this->intake->find($intakeActor, $requestId, true);
            $this->intake->policy->staff($intakeActor, $request, 'projects.convert');
            $fingerprint = $this->receipts->fingerprint('project.convert', $requestId, '', $etag, $key, []);
            $replay = $this->receipts->replay($actor, 'project.convert', $requestId, $fingerprint);
            if ($replay !== null) {
                $this->store->find($actor, $replay['project_id']);

                return $replay;
            }
            VersionPrecondition::require($etag, $requestId, $request->lock_version);
            if ($request->state !== RequestState::Approved) {
                throw new HttpException(409);
            }
            $baseline = $this->proposals->lockAccepted($requestId, $request->customer_id, $request->customer_user_id);
            $project = $this->projects->handle($actor, $baseline, $request->customer_user_id, $this->conversion->name($request), $correlation);
            $this->conversion->handle($intakeActor, $request, $correlation);

            return $this->receipts->record($actor, 'project.convert', $requestId, $fingerprint, $project, $project->id);
        }, 2);
    }
}
