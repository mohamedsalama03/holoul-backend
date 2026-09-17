<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Modules\ProjectIntake\Contracts\CommercialContext;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class CommercialIntake
{
    public function __construct(private IntakeStore $store) {}

    public function context(ProjectRequest $record, IntakeActor $actor, string $correlation, bool $recent): CommercialContext
    {
        return new CommercialContext($record->id, $record->customer_id, $record->customer_user_id, $record->state->value,
            $record->lock_version, $record->latest_revision_id ?? throw new HttpException(409), $actor->id,
            $actor->customerId !== null, $actor->verifiedEmail, $recent, $record->assigned_staff_id, $actor->permissions, $correlation);
    }

    public function change(ProjectRequest $record, ?RequestState $target, ?string $actorId, string $correlation, ?string $reason = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Commercial transitions require the outer locked transaction.');
        }
        if ($target !== null) {
            $allowed = match ($record->state) {
                RequestState::Discovery => [RequestState::Proposal],
                RequestState::Proposal => [RequestState::Approved, RequestState::Discovery, RequestState::Withdrawn],
                RequestState::Approved => [RequestState::Discovery, RequestState::Withdrawn],
                default => [],
            };
            if (! in_array($target, $allowed, true)) {
                throw new HttpException(409);
            }
            DB::table('request_state_changes')->insert(['id' => (string) Str::uuid7(), 'request_id' => $record->id,
                'from_state' => $record->state->value, 'to_state' => $target->value, 'actor_id' => $actorId,
                'reason' => $reason, 'entity_version' => $record->lock_version + 1, 'correlation_id' => $correlation]);
            $record->state = $target;
        }
        $this->store->changed($record);
    }
}
