<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Modules\Categories\Contracts\TaxonomyReader;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\ProjectIntake\Data\DraftValues;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Data\SubmissionContact;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\ProjectIntake\Models\RequestDraft;
use App\Modules\ProjectIntake\Models\RequestRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Canonical revision write shared by authorized customer and capability-based submission. */
final readonly class CommitSubmission
{
    public function __construct(private TaxonomyReader $taxonomy, private IntakeDocuments $documents, private IntakeStore $store) {}

    public function handle(ProjectRequest $record, RequestDraft $draft, DraftValues $values, SubmissionContact $contact,
        ?string $actorId, string $requestId): RequestRevision
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Submission requires the locked owner transaction.');
        }
        $values->requireComplete();
        $taxonomy = $this->taxonomy->selection($values->categoryId ?? throw new LogicException,
            $values->subcategoryId ?? throw new LogicException, true);
        $initial = $record->latest_revision_number === 0;
        $draft->forceFill($values->columns());
        $revision = RequestRevision::query()->forceCreate([
            'request_id' => $record->id, 'customer_id' => $record->customer_id, 'revision_number' => $record->latest_revision_number + 1,
            ...$values->columns(), 'category_label' => $taxonomy->categoryName, 'subcategory_label' => $taxonomy->subcategoryName,
            'full_name' => $contact->fullName, 'email' => $contact->email, 'phone_e164' => $contact->phoneE164,
            'submitted_by' => $actorId, 'submitted_at' => now(),
            'provenance' => $actorId === null ? 'guest_submission' : ($initial ? 'customer_submission' : 'customer_amendment'),
        ]);
        $owner = new DocumentOwner($record->id, $record->customer_id, $record->customer_user_id, $actorId, $requestId);
        $this->documents->snapshotOwned($record, $draft, $revision, $owner);
        $record->latest_revision_id = $revision->id;
        $record->latest_revision_number = $revision->revision_number;
        if ($initial) {
            $sequence = DB::scalar("SELECT nextval('request_reference_sequence')");
            if (! is_int($sequence) && ! is_string($sequence)) {
                throw new LogicException('Reference allocation failed.');
            }
            $record->reference = 'REQ-'.now()->utc()->format('Y').'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
            $record->submitted_at = $revision->submitted_at;
            DB::table('request_state_changes')->insert(['id' => (string) Str::uuid7(), 'request_id' => $record->id,
                'from_state' => $record->state->value, 'to_state' => RequestState::Submitted->value, 'actor_id' => $actorId,
                ...($actorId === null ? ['entity_version' => $record->lock_version + 1, 'correlation_id' => $requestId] : [])]);
            $record->state = RequestState::Submitted;
        }
        $draft->is_open = false;
        $draft->save();
        $this->store->changed($record);
        DB::table('intake_notification_intents')->insert(['id' => (string) Str::uuid7(), 'request_id' => $record->id, 'revision_id' => $revision->id,
            'kind' => $initial ? 'intake.submitted' : 'intake.amended']);

        return $revision;
    }
}
