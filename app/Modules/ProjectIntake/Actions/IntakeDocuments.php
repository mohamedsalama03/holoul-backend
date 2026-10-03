<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\DocumentState;
use App\Modules\Documents\Data\DocumentView;
use App\Modules\Documents\Data\UploadReservation;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\ProjectIntake\Models\RequestDraft;
use App\Modules\ProjectIntake\Models\RequestRevision;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class IntakeDocuments
{
    public function __construct(private IntakeStore $store, private DocumentService $documents, private IntakeDocumentReferences $references,
        private RecordAuditEvent $audit) {}

    public function owner(ProjectRequest $record, IntakeActor $actor, string $requestId): DocumentOwner
    {
        return new DocumentOwner($record->id, $record->customer_id, $record->customer_user_id, $actor->id, $requestId);
    }

    public function editable(ProjectRequest $record, IntakeActor $actor): RequestDraft
    {
        $this->store->policy->owner($actor, $record);
        $draft = $this->store->draft($record);
        if (! $draft->is_open || ($record->state !== RequestState::Draft && ! $record->state->amendable())) {
            throw new HttpException(409);
        }

        return $draft;
    }

    public function reserve(ProjectRequest $record, IntakeActor $actor, ?string $etag, string $filename, int $bytes, string $sha256, string $key, string $requestId): UploadReservation
    {
        $draft = $this->editable($record, $actor);
        $owner = $this->owner($record, $actor, $requestId);
        $reservation = $this->documents->reserve($owner, $filename, $bytes, $sha256, $key);
        $old = DB::table('intake_draft_documents')->where('draft_id', $draft->id)->value('document_id');
        if ($old === $reservation->documentId) {
            return $reservation;
        }
        if ($this->documents->metadata($owner, $reservation->documentId, true)->state !== DocumentState::Uploading) {
            throw new HttpException(409);
        }
        VersionPrecondition::require($etag, $record->id, $record->lock_version);
        DB::table('intake_draft_documents')->where('draft_id', $draft->id)->delete();
        DB::table('intake_draft_documents')->insert(['draft_id' => $draft->id, 'request_id' => $record->id,
            'customer_id' => $record->customer_id, 'document_id' => $reservation->documentId]);
        if (is_string($old) && ! $this->references->hasAny($old)) {
            $this->documents->requestDeletion($owner, $old);
        }
        $this->store->changed($record);
        $this->store->event(is_string($old) ? 'document_replaced' : 'document_attached', $record, $actor, $requestId);

        return $reservation;
    }

    public function upload(ProjectRequest $record, IntakeActor $actor, string $documentId, ?string $etag, string $requestId): UploadReservation
    {
        $draft = $this->editable($record, $actor);
        VersionPrecondition::require($etag, $record->id, $record->lock_version);
        if (! DB::table('intake_draft_documents')->where('draft_id', $draft->id)->where('document_id', $documentId)->exists()) {
            throw new HttpException(404);
        }

        return $this->documents->uploadReservation($this->owner($record, $actor, $requestId), $documentId);
    }

    public function remove(ProjectRequest $record, IntakeActor $actor, string $documentId, ?string $etag, string $requestId): DocumentView
    {
        $draft = $this->editable($record, $actor);
        VersionPrecondition::require($etag, $record->id, $record->lock_version);
        $owner = $this->owner($record, $actor, $requestId);
        $view = $this->documents->metadata($owner, $documentId, true);
        if (! DB::table('intake_draft_documents')->where('draft_id', $draft->id)->where('document_id', $documentId)->exists()) {
            throw new HttpException(404);
        }
        DB::table('intake_draft_documents')->where('draft_id', $draft->id)->delete();
        if (! $this->references->hasAny($documentId)) {
            $view = $this->documents->requestDeletion($owner, $documentId);
        }
        $this->store->changed($record);
        $this->store->event('document_removed', $record, $actor, $requestId);

        return $view;
    }

    public function readable(ProjectRequest $record, IntakeActor $actor, string $documentId, bool $download): void
    {
        if ($actor->customerId === null) {
            $this->store->policy->staff($actor, $record, $download ? 'documents.download' : 'documents.read', false);
        }
        $historical = DB::table('intake_revision_documents')->where('request_id', $record->id)->where('document_id', $documentId)->exists();
        $draft = $actor->customerId !== null && DB::table('intake_draft_documents')->where('request_id', $record->id)->where('document_id', $documentId)->exists();
        if (! $historical && ! $draft && ($download || $actor->customerId === null)) {
            throw new HttpException(404);
        }
    }

    public function snapshot(ProjectRequest $record, RequestDraft $draft, RequestRevision $revision, IntakeActor $actor, string $requestId): void
    {
        $this->snapshotOwned($record, $draft, $revision, $this->owner($record, $actor, $requestId));
    }

    public function snapshotOwned(ProjectRequest $record, RequestDraft $draft, RequestRevision $revision, DocumentOwner $owner): void
    {
        $id = DB::table('intake_draft_documents')->where('draft_id', $draft->id)->value('document_id');
        if (! is_string($id)) {
            return;
        }
        $this->documents->lockAttachable($owner, $id);
        DB::table('intake_revision_documents')->insert(['revision_id' => $revision->id, 'request_id' => $record->id,
            'customer_id' => $record->customer_id, 'document_id' => $id]);
    }

    /** The maintenance coordinator holds the parent and expired Uploading document locks. */
    public function detachExpiredUpload(ProjectRequest $record, string $documentId, string $requestId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Upload expiry requires its coordinated transaction.');
        }
        if (DB::table('intake_revision_documents')->where('document_id', $documentId)->exists()) {
            throw new HttpException(409);
        }
        $deleted = DB::table('intake_draft_documents')->where('request_id', $record->id)
            ->where('customer_id', $record->customer_id)->where('document_id', $documentId)->delete();
        if ($deleted !== 0) {
            $this->store->changed($record);
            // Maintenance has no human actor. Do not attribute expiry to the
            // customer whose abandoned reservation happens to be removed.
            $this->audit->handle('intake.document_expired', 'project_request', $record->id, $requestId);
        }
    }
}
