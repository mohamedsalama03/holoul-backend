<?php

declare(strict_types=1);

namespace App\Modules\Documents\Processing;

use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\PermanentOperationFailure;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\Documents\Contracts\DocumentReferences;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\DocumentState;
use App\Modules\Documents\Models\Document;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class DeleteDocument implements OperationHandler
{
    public function __construct(private PrivateObjectStore $storage, private DocumentReferences $references,
        private RecordAuditEvent $audit) {}

    public function execute(OperationClaim $operation): Closure
    {
        DocumentOperationFence::requireOutsideTransaction();
        $id = $operation->references['document_id'] ?? throw new PermanentOperationFailure('document_missing');
        $document = DB::transaction(function () use ($operation, $id): ?Document {
            DocumentOperationFence::lock($operation);
            $document = Document::query()->whereKey($id)->lockForUpdate()->first()
                ?? throw new PermanentOperationFailure('document_missing');
            if ($document->state !== DocumentState::Deleting || $document->delete_operation_id !== $operation->id) {
                return null;
            }
            if ($this->references->hasAny($id)) {
                throw new PermanentOperationFailure('document_retained');
            }

            return $document;
        });
        if ($document === null) {
            return static function (): void {};
        }
        if ($document->storage_version !== null) {
            // An ambiguous successful deletion can be repeated for this exact
            // immutable version; the adapter treats its absence as success.
            $this->storage->deleteVersion($document->storage_key, $document->storage_version);
        }

        return function () use ($operation, $id, $document): void {
            $current = Document::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($current->state !== DocumentState::Deleting || $current->delete_operation_id !== $operation->id
                || $current->storage_version !== $document->storage_version || $this->references->hasAny($id)) {
                throw new PermanentOperationFailure('document_delete_conflict');
            }
            $current->forceFill(['state' => DocumentState::Deleted, 'deleted_at' => now(), 'failure_code' => null,
                'lock_version' => $current->lock_version + 1])->save();
            $this->audit->handle('documents.deletion_completed', 'documents.document', $id,
                $operation->requestId ?? $operation->id, metadata: new SafeAuditMetadata([
                    'operation_id' => $operation->id, 'outcome' => 'succeeded',
                ]));
        };
    }
}
