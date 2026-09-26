<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class TransitionRequest
{
    public function __construct(private IntakeStore $store) {}

    public function handle(IntakeActor $actor, string $id, ?string $etag, string $action, ?string $reason, string $requestId): ProjectRequest
    {
        return DB::transaction(function () use ($actor, $id, $etag, $action, $reason, $requestId): ProjectRequest {
            $record = $this->store->mutable($actor, $id, $etag);
            if ($action === 'withdraw') {
                $this->store->policy->owner($actor, $record);
                if (! $record->state->withdrawable()) {
                    throw new HttpException(409);
                }
                $target = RequestState::Withdrawn;
            } else {
                $permission = match ($action) {
                    'review' => 'intake.review', 'discovery' => 'intake.discovery', 'reject' => 'intake.reject',
                    default => throw new HttpException(404),
                };
                $this->store->policy->staff($actor, $record, $permission);
                $valid = match ($action) {
                    'review' => $record->state === RequestState::Submitted,
                    'discovery' => $record->customer_id !== null && $record->state === RequestState::UnderReview,
                    'reject' => in_array($record->state, [RequestState::UnderReview, RequestState::InformationRequired, RequestState::Discovery], true),
                };
                if (! $valid) {
                    throw new HttpException(409);
                }
                if ($action === 'reject' && ($reason === null || trim($reason) === '')) {
                    throw ValidationException::withMessages(['message' => 'A rejection reason is required.']);
                }
                $target = match ($action) {
                    'review' => RequestState::UnderReview, 'discovery' => RequestState::Discovery, 'reject' => RequestState::Rejected,
                };
            }
            if ($record->information_request_id !== null) {
                DB::table('information_resolutions')->insert(['id' => (string) Str::uuid7(), 'information_request_id' => $record->information_request_id,
                    'request_id' => $id, 'resolution' => 'closed', 'resolved_by' => $actor->id]);
                $record->information_request_id = null;
            }
            if (in_array($target, [RequestState::Discovery, RequestState::Rejected, RequestState::Withdrawn], true)) {
                $this->store->draft($record)->forceFill(['is_open' => false])->save();
            }
            $this->store->transition($record, $target, $actor, $reason);
            $this->store->changed($record);
            $this->store->event($target->value, $record, $actor, $requestId);

            return $record;
        });
    }
}
