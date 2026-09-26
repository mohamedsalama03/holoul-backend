<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Events\RequestChanged;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\ProjectIntake\Models\RequestDraft;
use App\Modules\ProjectIntake\Policies\RequestPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class IntakeStore
{
    public function __construct(public RequestPolicy $policy, private RecordAuditEvent $audit) {}

    public function find(IntakeActor $actor, string $id, bool $lock = false, ?string $parentCustomer = null): ProjectRequest
    {
        if (! Str::isUuid($id, 7) || ($parentCustomer !== null && (! Str::isUuid($parentCustomer, 7) || $parentCustomer !== $actor->customerId))) {
            throw new HttpException(404);
        }
        $query = $this->policy->scope(ProjectRequest::query(), $actor)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        } elseif (DB::transactionLevel() > 0) {
            // Keep assignment/ownership stable through subsequent private child reads.
            $query->sharedLock();
        }

        return $query->first() ?? throw new HttpException(404);
    }

    public function mutable(IntakeActor $actor, string $id, ?string $etag): ProjectRequest
    {
        $record = $this->find($actor, $id, true);
        VersionPrecondition::require($etag, $record->id, $record->lock_version);

        return $record;
    }

    public function draft(ProjectRequest $record): RequestDraft
    {
        return RequestDraft::query()->where('request_id', $record->id)->firstOrFail();
    }

    public function changed(ProjectRequest $record): void
    {
        $record->lock_version++;
        $record->save();
    }

    public function event(string $event, ProjectRequest $record, IntakeActor $actor, string $requestId): void
    {
        $this->audit->handle('intake.'.$event, 'project_request', $record->id, $requestId, $actor->id, new SafeAuditMetadata(['outcome' => 'succeeded']));
        if ($record->customer_user_id !== null && in_array($event, ['submitted', 'amendment_submitted', 'information_requested'], true)) {
            Event::dispatch(new RequestChanged($record->id, $event, $record->lock_version,
                $record->customer_user_id, $record->assigned_staff_id, $requestId));
        }
    }

    public function transition(ProjectRequest $record, RequestState $target, IntakeActor $actor, ?string $reason = null): void
    {
        DB::table('request_state_changes')->insert(['id' => (string) Str::uuid7(), 'request_id' => $record->id,
            'from_state' => $record->state->value, 'to_state' => $target->value, 'actor_id' => $actor->id, 'reason' => $reason]);
        $record->state = $target;
    }
}
