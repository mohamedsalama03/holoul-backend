<?php

declare(strict_types=1);

namespace App\Modules\Documents\Processing;

use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\PermanentOperationFailure;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\Documents\Contracts\DocumentInspector;
use App\Modules\Documents\Contracts\MalwareScanner;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\DocumentState;
use App\Modules\Documents\Data\MalwareVerdict;
use App\Modules\Documents\Models\Document;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ScanDocument implements OperationHandler
{
    public function __construct(private PrivateObjectStore $storage, private MalwareScanner $scanner,
        private DocumentInspector $inspector, private RecordAuditEvent $audit) {}

    public function execute(OperationClaim $operation): Closure
    {
        DocumentOperationFence::requireOutsideTransaction();
        $id = $operation->references['document_id'] ?? throw new PermanentOperationFailure('document_missing');
        $document = DB::transaction(function () use ($operation, $id): ?Document {
            DocumentOperationFence::lock($operation);
            $document = Document::query()->whereKey($id)->lockForUpdate()->first()
                ?? throw new PermanentOperationFailure('document_missing');

            return $document->state === DocumentState::Quarantined && $document->scan_operation_id === $operation->id ? $document : null;
        });
        if ($document === null) {
            return static function (): void {};
        }
        $object = $document->object();
        $stream = null;
        try {
            $stream = $this->storage->openVerified($object);
            if ($this->scanner->scan($stream, $object->size) === MalwareVerdict::Infected) {
                $reason = 'malware_detected';
            } else {
                rewind($stream);
                $inspection = $this->inspector->inspect($stream, $object->size, $document->format);
                $reason = $inspection->safe ? null : $inspection->reasonCode;
            }
        } catch (Throwable $exception) {
            DB::transaction(function () use ($operation, $id): void {
                DocumentOperationFence::lock($operation);
                $document = Document::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($document->state === DocumentState::Quarantined && $document->scan_operation_id === $operation->id) {
                    $document->forceFill(['failure_code' => 'inspection_unavailable'])->save();
                }
            });
            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return function () use ($operation, $id, $object, $reason): void {
            $document = Document::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($document->state !== DocumentState::Quarantined || $document->scan_operation_id !== $operation->id
                || $document->storage_version !== $object->versionId || ! hash_equals($document->expected_sha256, $object->sha256)) {
                return;
            }
            $document->forceFill(['state' => $reason === null ? DocumentState::Available : DocumentState::Rejected,
                'available_at' => $reason === null ? now() : null, 'failure_code' => $reason,
                'lock_version' => $document->lock_version + 1])->save();
            $metadata = new SafeAuditMetadata(['operation_id' => $operation->id, 'attempt_number' => $operation->attempt,
                'outcome' => $reason === null ? 'succeeded' : 'failed']);
            $this->audit->handle($reason === null ? 'documents.scan_passed' : 'documents.rejected',
                'documents.document', $id, $operation->requestId ?? $operation->id, metadata: $metadata);
        };
    }
}
