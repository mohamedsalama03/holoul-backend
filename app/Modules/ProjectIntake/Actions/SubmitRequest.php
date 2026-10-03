<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\ProjectIntake\Data\DraftValues;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Data\SubmissionContact;
use App\Modules\ProjectIntake\Models\SubmissionKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class SubmitRequest
{
    public function __construct(private IntakeStore $store, private CustomerContactReader $contacts, private CommitSubmission $commit) {}

    public function handle(IntakeActor $actor, string $id, ?string $etag, ?string $key, string $requestId): SubmissionKey
    {
        if ($key === null || preg_match('/\A[A-Za-z0-9_.:-]{16,128}\z/D', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'A bounded unique key is required.']);
        }

        return DB::transaction(function () use ($actor, $id, $etag, $key, $requestId): SubmissionKey {
            $record = $this->store->find($actor, $id, true);
            $this->store->policy->owner($actor, $record);
            $hash = hash('sha256', json_encode(['operation' => 'intake.submit', 'request_id' => $id, 'if_match' => $etag, 'body' => (object) []], JSON_THROW_ON_ERROR));
            $keyHash = hash('sha256', $key);
            SubmissionKey::query()->where('actor_id', $actor->id)->where('operation', 'intake.submit')->where('key_hash', $keyHash)->where('expires_at', '<=', now())->delete();
            DB::table('intake_submission_keys')->insertOrIgnore(['id' => (string) Str::uuid7(), 'actor_id' => $actor->id,
                'key_hash' => $keyHash, 'input_hash' => $hash, 'request_id' => $id, 'expires_at' => now()->addHours(72)]);
            $claim = SubmissionKey::query()->where('actor_id', $actor->id)->where('operation', 'intake.submit')->where('key_hash', $keyHash)->lockForUpdate()->firstOrFail();
            if (! hash_equals($claim->input_hash, $hash) || $claim->request_id !== $id) {
                throw new HttpException(409);
            }
            if ($claim->revision_id !== null) {
                return $claim;
            }
            VersionPrecondition::require($etag, $id, $record->lock_version);
            $draft = $this->store->draft($record);
            if (! $draft->is_open || ($record->state !== RequestState::Draft && ! $record->state->amendable())
                || $draft->base_revision_number !== $record->latest_revision_number) {
                throw new HttpException(409);
            }
            $values = DraftValues::patch($draft, []);
            $values->requireComplete();
            if ($values->categoryId === null || $values->subcategoryId === null) {
                throw new HttpException(422);
            }
            $contact = $this->contacts->currentForIdentity($actor->id, true);
            if ($contact === null || $contact->customerId !== $record->customer_id) {
                throw new AuthorizationException;
            }
            $initial = $record->latest_revision_number === 0;
            if (trim($contact->fullName) === '' || trim($contact->email) === '' || trim($contact->phoneE164) === '') {
                throw ValidationException::withMessages(['profile_complete_required' => 'Complete your profile before submission.']);
            }
            $revision = $this->commit->handle($record, $draft, $values,
                new SubmissionContact($contact->fullName, $contact->email, $contact->phoneE164), $actor->id, $requestId);
            $this->store->event($initial ? 'submitted' : 'amendment_submitted', $record, $actor, $requestId);
            $claim->forceFill(['revision_id' => $revision->id, 'revision_number' => $revision->revision_number,
                'result_version' => $record->lock_version, 'result_state' => $record->state->value, 'reference' => $record->reference])->save();

            return $claim;
        });
    }
}
