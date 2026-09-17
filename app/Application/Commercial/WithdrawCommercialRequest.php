<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Modules\ProjectIntake\Actions\CommercialIntake;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\Proposals\Actions\ProposalLifecycle;

final readonly class WithdrawCommercialRequest
{
    public function __construct(private IntakeStore $intake, private CommercialIntake $transitions, private ProposalLifecycle $proposals) {}

    public function handle(IntakeActor $actor, ProjectRequest $request, bool $recent, ?string $reason, string $correlation): ProjectRequest
    {
        $this->intake->policy->owner($actor, $request);
        $context = $this->transitions->context($request, $actor, $correlation, $recent);
        $this->proposals->withdrawRequest($context, $reason);
        $this->transitions->change($request, RequestState::Withdrawn, $actor->id, $correlation, $reason);
        $this->intake->event('withdrawn', $request, $actor, $correlation);

        return $request;
    }
}
