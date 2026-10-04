<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\OperationRecorder;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Documents\Contracts\DocumentReferences;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentDownload;
use App\Modules\Documents\Data\DocumentFormat;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\DocumentState;
use App\Modules\Documents\Data\DocumentView;
use App\Modules\Documents\Data\StoredObject;
use App\Modules\Documents\Data\UploadReservation;
use App\Modules\Documents\DocumentPolicy;
use App\Modules\Documents\Models\Document;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ManageDocuments implements DocumentService
{
    public function __construct(private DocumentReferences $references, private OperationRecorder $operations,
        private RecordAuditEvent $audit) {}

    public function reserve(DocumentOwner $owner, string $filename, int $bytes, string $sha256, string $idempotencyKey): UploadReservation
    {
        self::requireTransaction();
        if (! Config::boolean('documents.uploads_enabled')) {
            throw new HttpException(503);
        }
        if ($bytes < 1 || $bytes > DocumentPolicy::MAX_BYTES) {
            throw ValidationException::withMessages(['bytes' => 'The document must be between 1 byte and 10 MiB.']);
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            throw ValidationException::withMessages(['sha256' => 'A SHA-256 checksum is required.']);
        }
        if (preg_match('/\A[A-Za-z0-9_.:-]{16,128}\z/D', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'A bounded unique key is required.']);
        }
        $name = self::filename($filename);
        $format = DocumentFormat::tryFrom(strtolower(pathinfo($name, PATHINFO_EXTENSION)));
        if ($format === null) {
            throw new HttpException(415);
        }
        $inputHash = hash('sha256', json_encode([$owner->parentId, $owner->customerId, $name, $bytes, $sha256], JSON_THROW_ON_ERROR));
        if ($owner->customerId === null) {
            // PostgreSQL, not the disposable limiter, owns the anonymous storage budget.
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('documents.guest-quota', 0))");
        } else {
            DB::table('document_quotas')->insertOrIgnore(['customer_id' => $owner->customerId]);
            DB::table('document_quotas')->where('customer_id', $owner->customerId)->lockForUpdate()->first();
        }
        $existing = Document::query()->where('uploader_id', $owner->actorId)->where('parent_id', $owner->parentId)
            ->where('reservation_key_hash', hash('sha256', $idempotencyKey))->lockForUpdate()->first();
        if ($existing !== null) {
            if (! hash_equals($existing->reservation_input_hash, $inputHash)
                || in_array($existing->state, [DocumentState::Deleting, DocumentState::Deleted], true)) {
                throw new HttpException(409);
            }

            return $this->reservation($existing);
        }
        $active = Document::query()->where('customer_id', $owner->customerId)->where('state', '<>', DocumentState::Deleted->value)
            ->get(['expected_size', 'state']);
        $uploading = $active->filter(fn (Document $document): bool => $document->state === DocumentState::Uploading)->count();
        $reserved = $active->reduce(fn (int $sum, Document $document): int => $sum + $document->expected_size, 0);
        if ($uploading >= ($owner->customerId === null ? 50 : DocumentPolicy::MAX_UPLOADING)
            || $reserved + $bytes > ($owner->customerId === null ? 500 * 1024 * 1024 : DocumentPolicy::MAX_RESERVED_BYTES)
            || $active->count() >= ($owner->customerId === null ? 100 : DocumentPolicy::MAX_DOCUMENTS)) {
            throw new HttpException(429, '', null, ['Retry-After' => '600']);
        }
        $id = (string) Str::uuid7();
        $document = Document::query()->forceCreate(['id' => $id, 'customer_id' => $owner->customerId,
            'customer_user_id' => $owner->userId, ...($owner->customerId === null ? ['guest_request_id' => $owner->parentId] : []), 'parent_id' => $owner->parentId, 'uploader_id' => $owner->actorId,
            'reservation_key_hash' => hash('sha256', $idempotencyKey), 'reservation_input_hash' => $inputHash,
            'display_name' => $name, 'format' => $format, 'expected_size' => $bytes, 'expected_sha256' => $sha256,
            'storage_key' => 'quarantine/'.$id, 'state' => DocumentState::Uploading,
            'upload_expires_at' => now()->addMinutes(DocumentPolicy::UPLOAD_MINUTES)]);
        $this->event('upload_reserved', $document, $owner);

        return $this->reservation($document->refresh());
    }

    public function uploadReservation(DocumentOwner $owner, string $documentId): UploadReservation
    {
        $document = $this->find($owner, $documentId, true);
        if (! Config::boolean('documents.uploads_enabled')) {
            throw new HttpException(503);
        }
        if (in_array($document->state, [DocumentState::Deleting, DocumentState::Deleted, DocumentState::Rejected], true)) {
            throw new HttpException(409);
        }

        return $this->reservation($document);
    }

    public function finalize(DocumentOwner $owner, string $documentId, StoredObject $object): DocumentView
    {
        $document = $this->find($owner, $documentId, true);
        $this->finalizeRecord($document, $object, $owner->requestId, $owner->actorId);

        return $this->view($document->refresh());
    }

    private function finalizeRecord(Document $document, StoredObject $object, string $requestId, ?string $actorId = null): void
    {
        self::requireTransaction();
        if ($object->key !== $document->storage_key || $object->size !== $document->expected_size
            || ! hash_equals($document->expected_sha256, $object->sha256) || $object->mime !== $document->format->mime()
            || $object->versionId === '' || strlen($object->versionId) > 1024) {
            throw new HttpException(409);
        }
        if ($document->storage_version !== null) {
            if ($document->storage_version !== $object->versionId || in_array($document->state, [DocumentState::Deleting, DocumentState::Deleted], true)) {
                throw new HttpException(409);
            }

            return;
        }
        if ($document->state !== DocumentState::Uploading) {
            throw new HttpException(409);
        }
        $operation = $this->operations->record('documents.scan', 'scan:'.$document->id.':1', ['document_id' => $document->id], $requestId);
        $document->forceFill(['storage_version' => $object->versionId, 'uploaded_at' => now(),
            'state' => DocumentState::Quarantined, 'scan_operation_id' => $operation->id, 'scan_generation' => 1,
            'lock_version' => $document->lock_version + 1, 'failure_code' => null])->save();
        $this->audit->handle('documents.upload_completed', 'documents.document', $document->id, $requestId, $actorId);
    }

    public function metadata(DocumentOwner $owner, string $documentId, bool $lock = false): DocumentView
    {
        return $this->view($this->find($owner, $documentId, $lock));
    }

    public function metadataMany(DocumentOwner $owner, array $documentIds): array
    {
        self::requireTransaction();
        if (count($documentIds) > 100 || count(array_unique($documentIds)) !== count($documentIds)) {
            throw ValidationException::withMessages(['documents' => 'At most 100 distinct document identifiers are allowed.']);
        }
        foreach ($documentIds as $id) {
            if (! Str::isUuid($id, 7)) {
                throw new HttpException(404);
            }
        }
        if ($documentIds === []) {
            return [];
        }
        $documents = Document::query()->whereIn('id', $documentIds)->where('parent_id', $owner->parentId)
            ->where('customer_id', $owner->customerId)->where('customer_user_id', $owner->userId)
            ->orderBy('id')->sharedLock()->get(['id', 'parent_id', 'customer_id', 'display_name', 'format',
                'expected_size', 'state', 'lock_version', 'manual_scan_retries', 'scan_operation_id']);
        if ($documents->count() !== count($documentIds)) {
            throw new HttpException(404);
        }
        $candidates = $documents->filter(static fn (Document $document): bool => $document->state === DocumentState::Quarantined
            && $document->manual_scan_retries < DocumentPolicy::MANUAL_SCAN_RETRIES && $document->scan_operation_id !== null);
        $failed = $candidates->isEmpty() ? [] : AsyncOperation::query()->whereIn('id', $candidates->pluck('scan_operation_id'))
            ->where('state', 'failed')->pluck('id')->all();
        $views = [];
        foreach ($documents as $document) {
            $views[$document->id] = $this->view($document, $candidates->contains('id', $document->id)
                && in_array($document->scan_operation_id, $failed, true));
        }

        return $views;
    }

    public function lockAttachable(DocumentOwner $owner, string $documentId): DocumentView
    {
        $document = $this->find($owner, $documentId, true);
        if (! in_array($document->state, [DocumentState::Quarantined, DocumentState::Available], true)) {
            throw new HttpException(409);
        }

        return $this->view($document);
    }

    public function requestDeletion(DocumentOwner $owner, string $documentId): DocumentView
    {
        $document = $this->find($owner, $documentId, true);
        $this->deleteRecord($document, $owner->requestId, $owner->actorId);

        return $this->view($document->refresh());
    }

    public function deleteRecord(Document $document, string $requestId, ?string $actorId = null): void
    {
        self::requireTransaction();
        if ($this->references->hasAny($document->id)) {
            throw new HttpException(409);
        }
        if (in_array($document->state, [DocumentState::Deleting, DocumentState::Deleted], true)) {
            return;
        }
        $operation = $this->operations->record('documents.delete', 'delete:'.$document->id, ['document_id' => $document->id], $requestId);
        $document->forceFill(['state' => DocumentState::Deleting, 'delete_operation_id' => $operation->id,
            'available_at' => null, 'lock_version' => $document->lock_version + 1])->save();
        $this->audit->handle('documents.deletion_requested', 'documents.document', $document->id, $requestId, $actorId);
    }

    public function retryScan(DocumentOwner $owner, string $documentId): DocumentView
    {
        $document = $this->find($owner, $documentId, true);
        if (! $this->retryable($document)) {
            throw new HttpException(409);
        }
        $generation = $document->scan_generation + 1;
        $operation = $this->operations->record('documents.scan', 'scan:'.$document->id.':'.$generation,
            ['document_id' => $document->id], $owner->requestId);
        $document->forceFill(['scan_operation_id' => $operation->id, 'scan_generation' => $generation,
            'manual_scan_retries' => $document->manual_scan_retries + 1, 'failure_code' => null,
            'lock_version' => $document->lock_version + 1])->save();
        $this->event('scan_retry_requested', $document, $owner);

        return $this->view($document);
    }

    public function authorizeDownload(DocumentOwner $owner, string $documentId): DocumentDownload
    {
        $document = $this->find($owner, $documentId, false);
        if ($document->state !== DocumentState::Available) {
            throw new HttpException(409);
        }
        $this->event('download_authorized', $document, $owner);

        return new DocumentDownload($this->view($document), $document->object(), $owner->actorId ?? throw new HttpException(403), now()->toImmutable()->addSeconds(60));
    }

    public function consumeDownload(DocumentOwner $owner, DocumentDownload $grant): void
    {
        $document = $this->find($owner, $grant->document->id, false);
        $expired = DB::scalar('SELECT clock_timestamp() > ?::timestamptz', [$grant->expiresAt->format('Y-m-d H:i:s.uP')]);
        if ($grant->actorId !== $owner->actorId || $expired !== false || $document->state !== DocumentState::Available
            || $document->lock_version !== $grant->document->version || $document->object() != $grant->object) {
            throw new HttpException(409);
        }
        $this->event('download_started', $document, $owner);
    }

    public function view(Document $document, ?bool $retryable = null): DocumentView
    {
        return new DocumentView($document->id, $document->parent_id, $document->customer_id, $document->display_name,
            $document->format, $document->expected_size, $document->state, $document->lock_version, $retryable ?? $this->retryable($document));
    }

    private function retryable(Document $document): bool
    {
        return $document->state === DocumentState::Quarantined && $document->manual_scan_retries < DocumentPolicy::MANUAL_SCAN_RETRIES
            && $document->scan_operation_id !== null && AsyncOperation::query()->whereKey($document->scan_operation_id)->where('state', 'failed')->exists();
    }

    private function find(DocumentOwner $owner, string $id, bool $lock): Document
    {
        self::requireTransaction();
        if (! Str::isUuid($id, 7)) {
            throw new HttpException(404);
        }
        $query = Document::query()->whereKey($id)->where('parent_id', $owner->parentId)
            ->where('customer_id', $owner->customerId)->where('customer_user_id', $owner->userId);

        return ($lock ? $query->lockForUpdate() : $query->sharedLock())->first() ?? throw new HttpException(404);
    }

    private function reservation(Document $document): UploadReservation
    {
        return new UploadReservation($document->id, $document->storage_key, $document->expected_size,
            $document->expected_sha256, $document->format, $document->upload_expires_at);
    }

    private function event(string $event, Document $document, DocumentOwner $owner): void
    {
        $this->audit->handle('documents.'.$event, 'documents.document', $document->id, $owner->requestId, $owner->actorId);
    }

    public static function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Document metadata requires the authorized transaction.');
        }
    }

    private static function filename(string $filename): string
    {
        if (strlen($filename) > 1024 || preg_match('//u', $filename) !== 1) {
            throw ValidationException::withMessages(['filename' => 'A valid display filename is required.']);
        }
        $name = basename(str_replace('\\', '/', $filename));
        $name = trim(preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 200 || in_array($name, ['.', '..'], true)) {
            throw ValidationException::withMessages(['filename' => 'A valid display filename is required.']);
        }

        return $name;
    }
}
