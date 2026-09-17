<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\OperationRecorder;
use App\Modules\Documents\Contracts\DocumentReferences;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\DocumentState;
use App\Modules\Documents\Data\ObjectVersion;
use App\Modules\Documents\DocumentPolicy;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\OrphanObject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final readonly class ReconcileDocuments
{
    public function __construct(private PrivateObjectStore $storage, private DocumentReferences $references,
        private ManageDocuments $documents, private OperationRecorder $operations) {}

    /** @return array{documents:int,objects:int} */
    public function handle(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Document reconciliation accepts 1 to 100 records.');
        }
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Document reconciliation cannot run inside a transaction.');
        }
        $requestId = (string) Str::uuid7();
        $count = 0;
        $documentCursor = DB::table('document_reconciliation_cursors')->where('id', 1)->value('document_cursor');
        $query = Document::query()->whereIn('state', ['uploading', 'quarantined', 'available', 'rejected'])
            ->whereRaw('created_at <= clock_timestamp() - make_interval(hours => ?)', [DocumentPolicy::ORPHAN_GRACE_HOURS])
            ->orderBy('id')->limit($limit);
        if (is_string($documentCursor)) {
            $query->where('id', '>', $documentCursor);
        }
        $candidates = $query->get(['id']);
        foreach ($candidates as $candidate) {
            $count += DB::transaction(function () use ($candidate, $requestId): int {
                $document = Document::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ($this->references->hasAny($document->id) || in_array($document->state, [DocumentState::Deleting, DocumentState::Deleted], true)) {
                    return 0;
                }
                if ($document->scan_operation_id !== null && AsyncOperation::query()->whereKey($document->scan_operation_id)
                    ->where('state', 'running')->where('lease_expires_at', '>', DB::raw('clock_timestamp()'))->exists()) {
                    return 0;
                }
                $this->documents->deleteRecord($document, $requestId);

                return 1;
            });
        }
        DB::table('document_reconciliation_cursors')->where('id', 1)->where('document_cursor', $documentCursor)
            ->update(['document_cursor' => $candidates->last()?->id]);
        $cursor = DB::table('document_reconciliation_cursors')->where('id', 1)->value('cursor');
        $cursor = is_string($cursor) ? $cursor : null;
        $page = $this->storage->versions($cursor, $limit);
        foreach ($page->objects as $object) {
            try {
                $this->object($object, $requestId);
            } catch (Throwable) {
                // A malformed/unavailable object cannot starve every later
                // page. The persistent cursor cycles back for another pass.
                Log::warning('documents.object_reconciliation_unavailable');
            }
        }
        DB::table('document_reconciliation_cursors')->where('id', 1)->where('cursor', $cursor)
            ->update(['cursor' => $page->nextCursor, 'updated_at' => now()]);

        return ['documents' => $count, 'objects' => count($page->objects)];
    }

    private function object(ObjectVersion $object, string $requestId): void
    {
        if (! str_starts_with($object->key, 'quarantine/') || ! Str::isUuid(substr($object->key, 11), 7)) {
            return;
        }
        // Storage presence cannot bypass a revoked identity or stale parent.
        // Only a freshly authorized API retry may finalize uploaded bytes.
        if (! DocumentPolicy::graceExpired($object->lastModified)) {
            return;
        }
        DB::transaction(function () use ($object, $requestId): void {
            $document = Document::query()->where('storage_key', $object->key)->lockForUpdate()->first();
            if ($document !== null && ($this->references->hasAny($document->id)
                || ! in_array($document->state, [DocumentState::Deleting, DocumentState::Deleted], true))) {
                return;
            }
            DB::table('document_orphan_objects')->insertOrIgnore(['id' => (string) Str::uuid7(),
                'storage_key' => $object->key, 'storage_version' => $object->versionId,
                'object_modified_at' => $object->lastModified, 'state' => 'deleting']);
            $orphan = OrphanObject::query()->where('storage_key', $object->key)->where('storage_version', $object->versionId)
                ->lockForUpdate()->firstOrFail();
            if ($orphan->operation_id === null) {
                $operation = $this->operations->record('documents.delete_orphan', 'orphan:'.$orphan->id,
                    ['orphan_id' => $orphan->id], $requestId);
                $orphan->forceFill(['operation_id' => $operation->id])->save();
            }
        });
    }
}
