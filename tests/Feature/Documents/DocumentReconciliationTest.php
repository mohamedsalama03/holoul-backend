<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Async\OperationRunner;
use App\Modules\Documents\Actions\ReconcileDocuments;
use App\Modules\Documents\Actions\RetryDocumentDeletions;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\StoredObject;
use App\Modules\Documents\Models\Document;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

final class DocumentReconciliationTest extends TestCase
{
    use DatabaseMigrations;
    use DocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeDocuments();
    }

    public function test_stored_unfinalized_upload_cannot_bypass_fresh_api_authorization(): void
    {
        $reservation = $this->reservation($this->documentOwner());
        $this->uploadFixture($reservation);
        app(ReconcileDocuments::class)->handle();
        $this->assertDatabaseHas('documents', ['id' => $reservation->documentId, 'state' => 'uploading', 'storage_version' => null]);
        $this->assertDatabaseCount('async_operations', 0);
        self::assertCount(1, $this->objects->objects);
    }

    public function test_orphan_cleanup_requires_24_hours_and_deletes_only_the_exact_version(): void
    {
        $old = $this->orphan(25);
        $fresh = $this->orphan(1);
        app(ReconcileDocuments::class)->handle();
        $this->assertDatabaseCount('document_orphan_objects', 1);
        $id = DB::table('document_orphan_objects')->where('storage_key', $old->key)->value('operation_id');
        self::assertIsString($id);
        app(OperationRunner::class)->run($id);
        app(OperationRunner::class)->run($id);
        self::assertArrayNotHasKey($old->key, $this->objects->objects);
        self::assertArrayHasKey($fresh->key, $this->objects->objects);
        $this->assertDatabaseHas('document_orphan_objects', ['storage_key' => $old->key, 'state' => 'deleted']);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.orphan_deleted')->count());
    }

    public function test_abandoned_unreferenced_reservation_is_tombstoned_then_its_late_object_is_cleaned(): void
    {
        $owner = $this->documentOwner();
        $this->travel(-25)->hours();
        try {
            $reservation = $this->reservation($owner);
        } finally {
            $this->travelBack();
        }
        $stored = $this->orphan(25, $reservation->key);
        app(ReconcileDocuments::class)->handle();
        $document = Document::query()->findOrFail($reservation->documentId);
        self::assertSame('deleting', $document->state->value);
        app(OperationRunner::class)->run($document->delete_operation_id);
        $orphanId = DB::table('document_orphan_objects')->where('storage_key', $stored->key)->value('operation_id');
        self::assertIsString($orphanId);
        app(OperationRunner::class)->run($orphanId);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'deleted']);
        self::assertSame([], $this->objects->objects);
    }

    public function test_referenced_uploading_reservation_and_object_remain_unavailable_and_retained(): void
    {
        $owner = $this->documentOwner();
        $this->travel(-25)->hours();
        try {
            $reservation = $this->reservation($owner);
        } finally {
            $this->travelBack();
        }
        $this->attachDraft($owner, $reservation->documentId);
        $stored = $this->orphan(25, $reservation->key);
        app(ReconcileDocuments::class)->handle();
        $this->assertDatabaseHas('documents', ['id' => $reservation->documentId, 'state' => 'uploading']);
        $this->assertDatabaseCount('document_orphan_objects', 0);
        $this->assertDatabaseCount('async_operations', 0);
        self::assertArrayHasKey($stored->key, $this->objects->objects);
    }

    public function test_persistent_metadata_cursor_prevents_retained_rows_from_starving_cleanup(): void
    {
        $firstOwner = $this->documentOwner();
        $secondOwner = $this->documentOwner();
        $this->travel(-25)->hours();
        try {
            $first = $this->reservation($firstOwner);
            $second = $this->reservation($secondOwner);
        } finally {
            $this->travelBack();
        }
        $this->attachDraft($firstOwner, $first->documentId);
        app(ReconcileDocuments::class)->handle(1);
        app(ReconcileDocuments::class)->handle(1);
        $this->assertDatabaseHas('documents', ['id' => $first->documentId, 'state' => 'uploading']);
        $this->assertDatabaseHas('documents', ['id' => $second->documentId, 'state' => 'deleting']);
    }

    public function test_exhausted_deletion_is_retried_only_by_explicit_audited_command_action(): void
    {
        [$owner, $document] = $this->quarantined();
        DB::transaction(fn () => app(DocumentService::class)->requestDeletion($owner, $document->id));
        $document->refresh();
        $original = $document->delete_operation_id;
        $this->objects->failDelete = true;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->due($original);
            app(OperationRunner::class)->run($original);
        }
        app(ReconcileDocuments::class)->handle();
        $this->assertDatabaseHas('async_operations', ['id' => $original, 'state' => 'failed', 'attempts' => 3]);
        self::assertSame($original, $document->refresh()->delete_operation_id);
        self::assertSame(1, app(RetryDocumentDeletions::class)->handle());
        self::assertSame(0, app(RetryDocumentDeletions::class)->handle());
        self::assertNotSame($original, $document->refresh()->delete_operation_id);
        $this->objects->failDelete = false;
        app(OperationRunner::class)->run($document->delete_operation_id);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'deleted']);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.deletion_retry_requested')->count());
    }

    public function test_explicit_orphan_retry_preserves_exact_version_and_appends_audit(): void
    {
        $stored = $this->orphan(25);
        app(ReconcileDocuments::class)->handle();
        $operation = DB::table('document_orphan_objects')->where('storage_key', $stored->key)->value('operation_id');
        self::assertIsString($operation);
        $this->objects->failDelete = true;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->due($operation);
            app(OperationRunner::class)->run($operation);
        }
        self::assertSame(1, app(RetryDocumentDeletions::class)->handle());
        $retry = DB::table('document_orphan_objects')->where('storage_key', $stored->key)->value('operation_id');
        self::assertIsString($retry);
        self::assertNotSame($operation, $retry);
        $this->objects->failDelete = false;
        app(OperationRunner::class)->run($retry);
        self::assertSame([], $this->objects->objects);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.orphan_retry_requested')->count());
    }

    public function test_explicit_retry_cannot_delete_an_object_now_retained_by_an_attachment(): void
    {
        [$owner, $document] = $this->quarantined();
        $this->attachDraft($owner, $document->id);
        $orphanId = (string) Str::uuid7();
        $operation = app(OperationRecorder::class)->record('documents.delete_orphan', 'stale-orphan-fixture:'.$orphanId, ['orphan_id' => $orphanId]);
        DB::table('async_operations')->where('id', $operation->id)->update(['state' => 'failed', 'attempts' => 3,
            'completed_at' => now(), 'failure_code' => 'execution_failed']);
        DB::table('document_orphan_objects')->insert(['id' => $orphanId, 'storage_key' => $document->storage_key,
            'storage_version' => $document->storage_version, 'object_modified_at' => now()->subHours(25),
            'state' => 'deleting', 'operation_id' => $operation->id]);
        self::assertSame(0, app(RetryDocumentDeletions::class)->handle());
        $this->assertDatabaseHas('document_orphan_objects', ['id' => $orphanId, 'operation_id' => $operation->id]);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'quarantined']);
        self::assertArrayHasKey($document->storage_key, $this->objects->objects);
        self::assertSame(0, DB::table('audit_events')->where('event_type', 'documents.orphan_retry_requested')->count());
    }

    private function orphan(int $hours, ?string $key = null): StoredObject
    {
        $object = new StoredObject($key ?? 'quarantine/'.Str::uuid7(), 'version-'.Str::uuid7(), strlen(self::DOCUMENT_PDF),
            hash('sha256', self::DOCUMENT_PDF), 'application/pdf');
        $this->objects->objects[$object->key] = ['object' => $object, 'bytes' => self::DOCUMENT_PDF,
            'created' => now()->subHours($hours)->toImmutable()];

        return $object;
    }

    private function attachDraft(DocumentOwner $owner, string $documentId): void
    {
        DB::table('intake_draft_documents')->insert(['draft_id' => DB::table('request_drafts')->where('request_id', $owner->parentId)->value('id'),
            'request_id' => $owner->parentId, 'customer_id' => $owner->customerId, 'document_id' => $documentId]);
    }
}
