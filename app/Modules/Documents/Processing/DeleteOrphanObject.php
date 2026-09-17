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
use App\Modules\Documents\DocumentPolicy;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\OrphanObject;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class DeleteOrphanObject implements OperationHandler
{
    public function __construct(private PrivateObjectStore $storage, private DocumentReferences $references,
        private RecordAuditEvent $audit) {}

    public function execute(OperationClaim $operation): Closure
    {
        DocumentOperationFence::requireOutsideTransaction();
        $id = $operation->references['orphan_id'] ?? throw new PermanentOperationFailure('orphan_missing');
        $candidate = OrphanObject::query()->find($id) ?? throw new PermanentOperationFailure('orphan_missing');
        $orphan = DB::transaction(function () use ($operation, $candidate): ?OrphanObject {
            DocumentOperationFence::lock($operation);
            $document = Document::query()->where('storage_key', $candidate->storage_key)->lockForUpdate()->first();
            $orphan = OrphanObject::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($orphan->state !== 'deleting' || $orphan->operation_id !== $operation->id) {
                return null;
            }
            if (! DocumentPolicy::graceExpired($orphan->object_modified_at)) {
                throw new PermanentOperationFailure('orphan_grace_active');
            }
            if ($document !== null && ($this->references->hasAny($document->id)
                || ! in_array($document->state, [DocumentState::Deleting, DocumentState::Deleted], true))) {
                $orphan->forceFill(['state' => 'retained'])->save();

                return null;
            }

            return $orphan;
        });
        if ($orphan === null) {
            return static function (): void {};
        }
        $this->storage->deleteVersion($orphan->storage_key, $orphan->storage_version);

        return function () use ($operation, $orphan): void {
            $current = OrphanObject::query()->whereKey($orphan->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'deleting' || $current->operation_id !== $operation->id) {
                throw new PermanentOperationFailure('orphan_delete_conflict');
            }
            $current->forceFill(['state' => 'deleted'])->save();
            $this->audit->handle('documents.orphan_deleted', 'documents.orphan', $current->id,
                $operation->requestId ?? $operation->id, metadata: new SafeAuditMetadata([
                    'operation_id' => $operation->id, 'outcome' => 'succeeded',
                ]));
        };
    }
}
