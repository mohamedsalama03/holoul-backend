<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Async\OperationState;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\Documents\Contracts\DocumentReferences;
use App\Modules\Documents\Data\DocumentState;
use App\Modules\Documents\DocumentPolicy;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\OrphanObject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Explicit operator action; ordinary reconciliation never resets attempts. */
final readonly class RetryDocumentDeletions
{
    public function __construct(private DocumentReferences $references, private OperationRecorder $operations,
        private RecordAuditEvent $audit) {}

    public function handle(int $limit = 20): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Deletion retry accepts 1 to 100 operations.');
        }
        $requestId = (string) Str::uuid7();
        $count = 0;
        $documents = Document::query()->where('state', 'deleting')->whereIn('delete_operation_id',
            AsyncOperation::query()->where('state', 'failed')->select('id'))->orderBy('id')->limit($limit)->get();
        foreach ($documents as $candidate) {
            $count += DB::transaction(function () use ($candidate, $requestId): int {
                $prior = AsyncOperation::query()->whereKey($candidate->delete_operation_id)->lockForUpdate()->first();
                $document = Document::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ($prior === null || $prior->state !== OperationState::Failed || $document->delete_operation_id !== $prior->id
                    || $document->state !== DocumentState::Deleting || $this->references->hasAny($document->id)) {
                    return 0;
                }
                $operation = $this->operations->record('documents.delete', 'delete-retry:'.$document->id.':'.$prior->id,
                    ['document_id' => $document->id], $requestId);
                $document->forceFill(['delete_operation_id' => $operation->id, 'lock_version' => $document->lock_version + 1])->save();
                $this->audit->handle('documents.deletion_retry_requested', 'documents.document', $document->id, $requestId,
                    metadata: new SafeAuditMetadata(['operation_id' => $operation->id]));

                return 1;
            });
        }
        $remaining = $limit - $documents->count();
        if ($remaining < 1) {
            return $count;
        }
        $orphans = OrphanObject::query()->where('state', 'deleting')->whereIn('operation_id',
            AsyncOperation::query()->where('state', 'failed')->select('id'))->orderBy('id')->limit($remaining)->get();
        foreach ($orphans as $candidate) {
            $count += DB::transaction(function () use ($candidate, $requestId): int {
                $prior = AsyncOperation::query()->whereKey($candidate->operation_id)->lockForUpdate()->first();
                $document = Document::query()->where('storage_key', $candidate->storage_key)->lockForUpdate()->first();
                $orphan = OrphanObject::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ($prior === null || $prior->state !== OperationState::Failed || $orphan->operation_id !== $prior->id
                    || $orphan->state !== 'deleting' || ! DocumentPolicy::graceExpired($orphan->object_modified_at)) {
                    return 0;
                }
                if ($document !== null && ($this->references->hasAny($document->id)
                    || ! in_array($document->state, [DocumentState::Deleting, DocumentState::Deleted], true))) {
                    return 0;
                }
                $operation = $this->operations->record('documents.delete_orphan', 'orphan-retry:'.$orphan->id.':'.$prior->id,
                    ['orphan_id' => $orphan->id], $requestId);
                $orphan->forceFill(['operation_id' => $operation->id])->save();
                $this->audit->handle('documents.orphan_retry_requested', 'documents.orphan', $orphan->id, $requestId,
                    metadata: new SafeAuditMetadata(['operation_id' => $operation->id]));

                return 1;
            });
        }

        return $count;
    }
}
