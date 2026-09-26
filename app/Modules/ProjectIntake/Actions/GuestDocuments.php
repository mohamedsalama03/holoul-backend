<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\UploadReservation;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The application must first lock and authorize the browser-bound guest capability. */
final readonly class GuestDocuments
{
    public function __construct(private IntakeStore $store, private DocumentService $documents, private RecordAuditEvent $audit) {}

    public function owner(ProjectRequest $record, string $correlation): DocumentOwner
    {
        if (! $record->guest_origin || $record->customer_id !== null || $record->state !== RequestState::Draft) {
            throw new HttpException(404);
        }

        return new DocumentOwner($record->id, null, null, null, $correlation);
    }

    public function reserve(ProjectRequest $record, ?string $etag, string $filename, int $bytes, string $sha256, string $key, string $correlation): UploadReservation
    {
        $owner = $this->owner($record, $correlation);
        $draft = $this->store->draft($record);
        $old = DB::table('intake_draft_documents')->where('draft_id', $draft->id)->value('document_id');
        $reservation = $this->documents->reserve($owner, $filename, $bytes, $sha256, $key);
        if ($old === $reservation->documentId) {
            return $reservation;
        }
        if ($old !== null) {
            throw new HttpException(409);
        }
        VersionPrecondition::require($etag, $record->id, $record->lock_version);
        DB::table('intake_draft_documents')->insert(['draft_id' => $draft->id, 'request_id' => $record->id,
            'customer_id' => null, 'document_id' => $reservation->documentId]);
        $this->store->changed($record);
        $this->audit->handle('intake.guest_document_attached', 'project_request', $record->id, $correlation);

        return $reservation;
    }

    public function upload(ProjectRequest $record, string $documentId, ?string $etag, string $correlation): UploadReservation
    {
        $owner = $this->owner($record, $correlation);
        VersionPrecondition::require($etag, $record->id, $record->lock_version);
        $this->attached($record, $documentId);

        return $this->documents->uploadReservation($owner, $documentId);
    }

    public function attached(ProjectRequest $record, string $documentId): void
    {
        if (! DB::table('intake_draft_documents')->where('request_id', $record->id)->where('document_id', $documentId)->exists()) {
            throw new HttpException(404);
        }
    }
}
