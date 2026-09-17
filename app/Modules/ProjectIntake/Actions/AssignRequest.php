<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class AssignRequest
{
    public function __construct(private IntakeStore $store) {}

    /** The outer workflow validates and locks the candidate through Identity's authorized staff contract. */
    public function handle(IntakeActor $actor, string $id, ?string $etag, string $assigneeId, string $requestId): ProjectRequest
    {
        return DB::transaction(function () use ($actor, $id, $etag, $assigneeId, $requestId): ProjectRequest {
            $record = $this->store->mutable($actor, $id, $etag);
            $this->store->policy->staff($actor, $record, 'intake.assign', false);
            if (! in_array($record->state, [RequestState::Submitted, RequestState::UnderReview, RequestState::InformationRequired, RequestState::Discovery, RequestState::Proposal, RequestState::Approved], true)
                || $record->assigned_staff_id === $assigneeId) {
                throw new HttpException(409);
            }
            DB::table('request_assignments')->insert(['id' => (string) Str::uuid7(), 'request_id' => $id,
                'previous_staff_id' => $record->assigned_staff_id, 'assigned_staff_id' => $assigneeId, 'assigned_by' => $actor->id]);
            $event = $record->assigned_staff_id === null ? 'assigned' : 'reassigned';
            $record->assigned_staff_id = $assigneeId;
            $this->store->changed($record);
            $this->store->event($event, $record, $actor, $requestId);

            return $record;
        });
    }
}
