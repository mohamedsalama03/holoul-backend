<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\InformationRequest;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class InformationWorkflow
{
    public function __construct(private IntakeStore $store) {}

    public function ask(IntakeActor $actor, string $id, ?string $etag, string $message, string $requestId): ProjectRequest
    {
        return DB::transaction(function () use ($actor, $id, $etag, $message, $requestId): ProjectRequest {
            $record = $this->store->mutable($actor, $id, $etag);
            $this->store->policy->staff($actor, $record, 'intake.information');
            if (! in_array($record->state, [RequestState::UnderReview, RequestState::Discovery], true)) {
                throw new HttpException(409);
            }
            $question = InformationRequest::query()->forceCreate(['request_id' => $id, 'customer_id' => $record->customer_id,
                'origin_state' => $record->state->value, 'question' => $message, 'requested_by' => $actor->id]);
            $record->information_request_id = $question->id;
            $this->store->transition($record, RequestState::InformationRequired, $actor);
            $this->store->changed($record);
            $this->store->event('information_requested', $record, $actor, $requestId);

            return $record;
        });
    }

    public function respond(IntakeActor $actor, string $id, string $informationId, ?string $etag, string $message, string $requestId): ProjectRequest
    {
        return DB::transaction(function () use ($actor, $id, $informationId, $etag, $message, $requestId): ProjectRequest {
            $record = $this->store->mutable($actor, $id, $etag);
            $this->store->policy->owner($actor, $record);
            $this->question($record, $informationId);
            if (DB::table('information_responses')->where('information_request_id', $informationId)->exists()) {
                throw new HttpException(409);
            }
            DB::table('information_responses')->insert(['id' => (string) Str::uuid7(), 'information_request_id' => $informationId,
                'request_id' => $id, 'customer_id' => $record->customer_id, 'response' => $message, 'responded_by' => $actor->id]);
            $this->store->changed($record);
            $this->store->event('information_responded', $record, $actor, $requestId);

            return $record;
        });
    }

    public function acknowledge(IntakeActor $actor, string $id, string $informationId, ?string $etag, string $requestId): ProjectRequest
    {
        return DB::transaction(function () use ($actor, $id, $informationId, $etag, $requestId): ProjectRequest {
            $record = $this->store->mutable($actor, $id, $etag);
            $this->store->policy->staff($actor, $record, 'intake.information');
            $question = $this->question($record, $informationId);
            if (! DB::table('information_responses')->where('information_request_id', $informationId)->exists()) {
                throw new HttpException(409);
            }
            // The originating phase is persisted when asking; no client target is accepted.
            $target = RequestState::from($question->origin_state);
            if (! in_array($target, [RequestState::UnderReview, RequestState::Discovery], true)) {
                throw new HttpException(409);
            }
            DB::table('information_resolutions')->insert(['id' => (string) Str::uuid7(), 'information_request_id' => $informationId,
                'request_id' => $id, 'resolution' => 'acknowledged', 'resolved_by' => $actor->id]);
            $record->information_request_id = null;
            $this->store->transition($record, $target, $actor);
            $this->store->changed($record);
            $this->store->event('information_acknowledged', $record, $actor, $requestId);

            return $record;
        });
    }

    private function question(ProjectRequest $record, string $id): InformationRequest
    {
        if (! Str::isUuid($id, 7)) {
            throw new HttpException(404);
        }
        $question = InformationRequest::query()->where('request_id', $record->id)->whereKey($id)->first() ?? throw new HttpException(404);
        if ($record->state !== RequestState::InformationRequired || $record->information_request_id !== $id) {
            throw new HttpException(409);
        }

        return $question;
    }
}
