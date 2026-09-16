<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Modules\Categories\Contracts\TaxonomyReader;
use App\Modules\ProjectIntake\Data\DraftValues;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\ProjectIntake\Models\RequestDraft;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ManageDraft
{
    public function __construct(private IntakeStore $store, private TaxonomyReader $taxonomy) {}

    /** @param array<string,mixed> $input */
    public function create(IntakeActor $actor, array $input, string $requestId): ProjectRequest
    {
        if ($actor->customerId === null) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $input, $requestId): ProjectRequest {
            $values = DraftValues::patch(null, $input);
            $this->selection($values);
            $record = ProjectRequest::query()->forceCreate(['customer_id' => $actor->customerId,
                'customer_user_id' => $actor->id, 'state' => RequestState::Draft, 'lock_version' => 1, 'latest_revision_number' => 0]);
            RequestDraft::query()->forceCreate(['request_id' => $record->id, 'customer_id' => $actor->customerId, 'is_open' => true,
                'base_revision_number' => 0, ...$values->columns()]);
            $this->store->event('draft_created', $record, $actor, $requestId);

            return $record;
        });
    }

    /** @param array<string,mixed> $input */
    public function update(IntakeActor $actor, string $id, ?string $etag, array $input, string $requestId): ProjectRequest
    {
        return DB::transaction(function () use ($actor, $id, $etag, $input, $requestId): ProjectRequest {
            $record = $this->store->mutable($actor, $id, $etag);
            $this->store->policy->owner($actor, $record);
            $draft = $this->store->draft($record);
            if (! $draft->is_open || ($record->state !== RequestState::Draft && ! $record->state->amendable())) {
                throw new HttpException(409);
            }
            $values = DraftValues::patch($draft, $input);
            $this->selection($values);
            $draft->forceFill($values->columns())->save();
            $this->store->changed($record);
            $this->store->event('draft_updated', $record, $actor, $requestId);

            return $record;
        });
    }

    public function amend(IntakeActor $actor, string $id, ?string $etag, string $requestId): ProjectRequest
    {
        return DB::transaction(function () use ($actor, $id, $etag, $requestId): ProjectRequest {
            $record = $this->store->mutable($actor, $id, $etag);
            $this->store->policy->owner($actor, $record);
            $draft = $this->store->draft($record);
            if (! $record->state->amendable() || $draft->is_open) {
                throw new HttpException(409);
            }
            $draft->forceFill(['is_open' => true, 'base_revision_number' => $record->latest_revision_number])->save();
            $this->store->changed($record);
            $this->store->event('amendment_started', $record, $actor, $requestId);

            return $record;
        });
    }

    private function selection(DraftValues $values): void
    {
        if ($values->categoryId !== null && $values->subcategoryId !== null) {
            $this->taxonomy->selection($values->categoryId, $values->subcategoryId, true);
        }
    }
}
