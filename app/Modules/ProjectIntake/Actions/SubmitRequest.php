<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Categories\Contracts\TaxonomyReader;
use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\ProjectIntake\Data\DraftValues;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\RequestRevision;
use App\Modules\ProjectIntake\Models\SubmissionKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class SubmitRequest
{
    public function __construct(private IntakeStore $store, private TaxonomyReader $taxonomy, private CustomerContactReader $contacts) {}

    public function handle(IntakeActor $actor, string $id, ?string $etag, ?string $key, string $requestId): SubmissionKey
    {
        if ($key === null || preg_match('/\A[A-Za-z0-9_.:-]{16,128}\z/D', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'A bounded unique key is required.']);
        }

        return DB::transaction(function () use ($actor, $id, $etag, $key, $requestId): SubmissionKey {
            $record = $this->store->find($actor, $id, true);
            $this->store->policy->owner($actor, $record);
            if (! $actor->verifiedEmail) {
                throw new AuthorizationException;
            }
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
            $taxonomy = $this->taxonomy->selection($values->categoryId, $values->subcategoryId, true);
            $contact = $this->contacts->currentForIdentity($actor->id, true);
            if ($contact === null || $contact->customerId !== $record->customer_id || ! $contact->verifiedEmail) {
                throw new AuthorizationException;
            }
            $initial = $record->latest_revision_number === 0;
            $revision = RequestRevision::query()->forceCreate([
                'request_id' => $id, 'customer_id' => $record->customer_id, 'revision_number' => $record->latest_revision_number + 1,
                ...$values->columns(), 'category_label' => $taxonomy->categoryName, 'subcategory_label' => $taxonomy->subcategoryName,
                'full_name' => $contact->fullName, 'email' => $contact->email, 'phone_e164' => $contact->phoneE164,
                'submitted_by' => $actor->id, 'submitted_at' => now(), 'provenance' => $initial ? 'customer_submission' : 'customer_amendment',
            ]);
            $record->latest_revision_id = $revision->id;
            $record->latest_revision_number = $revision->revision_number;
            if ($initial) {
                $sequence = DB::scalar("SELECT nextval('request_reference_sequence')");
                if (! is_int($sequence) && ! is_string($sequence)) {
                    throw new \LogicException('Reference allocation failed.');
                }
                $record->reference = 'REQ-'.now()->utc()->format('Y').'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
                $record->submitted_at = $revision->submitted_at;
                $this->store->transition($record, RequestState::Submitted, $actor);
            }
            $draft->is_open = false;
            $draft->save();
            $this->store->changed($record);
            DB::table('intake_notification_intents')->insert(['id' => (string) Str::uuid7(), 'request_id' => $id, 'revision_id' => $revision->id,
                'kind' => $initial ? 'intake.submitted' : 'intake.amended']);
            $this->store->event($initial ? 'submitted' : 'amendment_submitted', $record, $actor, $requestId);
            $claim->forceFill(['revision_id' => $revision->id, 'revision_number' => $revision->revision_number,
                'result_version' => $record->lock_version, 'result_state' => $record->state->value, 'reference' => $record->reference])->save();

            return $claim;
        });
    }
}
