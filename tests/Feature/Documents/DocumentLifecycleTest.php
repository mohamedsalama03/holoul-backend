<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Infrastructure\Async\LostOperationLease;
use App\Infrastructure\Async\OperationRunner;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentDownload;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\MalwareVerdict;
use App\Modules\Documents\Exceptions\StorageUnavailable;
use App\Modules\Documents\Processing\ScanDocument;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

final class DocumentLifecycleTest extends TestCase
{
    use DatabaseMigrations;
    use DocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeDocuments();
    }

    public function test_reservation_replay_is_exact_and_sanitizes_display_name_only(): void
    {
        $owner = $this->documentOwner();
        $key = (string) Str::uuid7();
        $reservation = $this->reservation($owner, $key, "../private\\safe\u{202e}\r\n.pdf");
        $replay = $this->reservation($owner, $key, "../private\\safe\u{202e}\r\n.pdf");
        self::assertEquals($reservation, $replay);
        self::assertSame('quarantine/'.$reservation->documentId, $reservation->key);
        $this->assertDatabaseHas('documents', ['id' => $reservation->documentId, 'display_name' => 'safe.pdf']);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('async_operations', 0);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.upload_reserved')->count());
        $this->expectException(HttpException::class);
        $this->reservation($owner, $key, 'different.pdf');
    }

    public function test_quota_serializes_and_limits_incomplete_uploads(): void
    {
        $owner = $this->documentOwner();
        $this->reservation($owner);
        $this->reservation($owner);
        try {
            $this->reservation($owner);
            self::fail('Third incomplete upload bypassed quota.');
        } catch (HttpException $exception) {
            self::assertSame(429, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('documents', 2);
        $this->assertDatabaseCount('document_quotas', 1);
    }

    public function test_object_storage_never_runs_inside_an_authorization_transaction(): void
    {
        $reservation = $this->reservation($this->documentOwner());
        $this->expectException(LogicException::class);
        DB::transaction(fn () => $this->uploadFixture($reservation));
    }

    public function test_failed_upload_keeps_reservation_and_retry_uses_identical_generated_key(): void
    {
        $owner = $this->documentOwner();
        $reservation = $this->reservation($owner);
        $this->objects->failPut = true;
        try {
            $this->uploadFixture($reservation);
            self::fail('Storage failure was ignored.');
        } catch (StorageUnavailable) {
            $this->assertDatabaseHas('documents', ['id' => $reservation->documentId, 'state' => 'uploading', 'storage_version' => null]);
        }
        self::assertSame([], $this->objects->objects);
        $this->objects->failPut = false;
        $first = $this->uploadFixture($reservation);
        self::assertEquals($first, $this->uploadFixture($reservation));
        self::assertCount(1, $this->objects->objects);
    }

    public function test_upload_success_and_finalize_rollback_recover_only_through_authorized_retry(): void
    {
        $owner = $this->documentOwner();
        $reservation = $this->reservation($owner);
        $object = $this->uploadFixture($reservation);
        try {
            DB::transaction(function () use ($owner, $reservation, $object): void {
                app(DocumentService::class)->finalize($owner, $reservation->documentId, $object);
                throw new RuntimeException('Simulated transaction failure.');
            });
        } catch (RuntimeException) {
            $this->assertDatabaseHas('documents', ['id' => $reservation->documentId, 'state' => 'uploading']);
        }
        $this->assertDatabaseCount('async_operations', 0);
        self::assertCount(1, $this->objects->objects);
        $sameObject = $this->uploadFixture($reservation);
        DB::transaction(fn () => app(DocumentService::class)->finalize($owner, $reservation->documentId, $sameObject));
        DB::transaction(fn () => app(DocumentService::class)->finalize($owner, $reservation->documentId, $sameObject));
        $this->assertDatabaseCount('async_operations', 1);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.upload_completed')->count());
    }

    public function test_duplicate_delivery_produces_one_available_result_and_audit(): void
    {
        [, $document] = $this->quarantined();
        $runner = app(OperationRunner::class);
        $runner->run($document->scan_operation_id);
        $runner->run($document->scan_operation_id);
        self::assertSame(1, $this->scanner->calls);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'available']);
        $this->assertDatabaseHas('async_operations', ['id' => $document->scan_operation_id, 'state' => 'succeeded', 'attempts' => 1, 'max_attempts' => 3]);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.scan_passed')->count());
    }

    public function test_scanner_outage_stays_quarantined_then_retry_can_succeed(): void
    {
        [, $document] = $this->quarantined();
        $this->scanner->unavailable = true;
        app(OperationRunner::class)->run($document->scan_operation_id);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'quarantined', 'failure_code' => 'inspection_unavailable']);
        $this->assertDatabaseHas('async_operations', ['id' => $document->scan_operation_id, 'state' => 'pending', 'failure_code' => 'execution_failed']);
        $this->scanner->unavailable = false;
        $this->due($document->scan_operation_id);
        app(OperationRunner::class)->run($document->scan_operation_id);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'available', 'failure_code' => null]);
    }

    public function test_exhausted_scan_requires_explicit_bounded_retry(): void
    {
        [$owner, $document] = $this->quarantined();
        $this->scanner->unavailable = true;
        for ($round = 0; $round < 3; $round++) {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $this->due($document->scan_operation_id);
                app(OperationRunner::class)->run($document->scan_operation_id);
            }
            $this->assertDatabaseHas('async_operations', ['id' => $document->scan_operation_id, 'state' => 'failed', 'attempts' => 3]);
            if ($round < 2) {
                $view = DB::transaction(fn () => app(DocumentService::class)->metadata($owner, $document->id));
                self::assertTrue($view->retryable);
                DB::transaction(fn () => app(DocumentService::class)->retryScan($owner, $document->id));
                $document->refresh();
            }
        }
        self::assertSame(9, $this->scanner->calls);
        $this->assertDatabaseCount('async_operations', 3);
        self::assertSame(2, DB::table('audit_events')->where('event_type', 'documents.scan_retry_requested')->count());
        $this->expectException(HttpException::class);
        DB::transaction(fn () => app(DocumentService::class)->retryScan($owner, $document->id));
    }

    public function test_malware_rejection_never_creates_available_result(): void
    {
        [$owner, $document] = $this->quarantined();
        $this->scanner->verdict = MalwareVerdict::Infected;
        app(OperationRunner::class)->run($document->scan_operation_id);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'rejected', 'failure_code' => 'malware_detected']);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.rejected')->count());
        self::assertSame(0, DB::table('audit_events')->where('event_type', 'documents.scan_passed')->count());
        $this->expectException(HttpException::class);
        DB::transaction(fn () => app(DocumentService::class)->authorizeDownload($owner, $document->id));
    }

    #[DataProvider('structuralFailures')]
    public function test_unsafe_structure_is_terminally_rejected(string $reason): void
    {
        [, $document] = $this->quarantined();
        $this->inspector->rejection = $reason;
        app(OperationRunner::class)->run($document->scan_operation_id);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'rejected', 'failure_code' => $reason]);
        $this->assertDatabaseHas('async_operations', ['id' => $document->scan_operation_id, 'state' => 'succeeded']);
    }

    public static function structuralFailures(): iterable
    {
        foreach (['invalid_structure', 'dangerous_content', 'resource_limit', 'encrypted_document', 'format_mismatch'] as $reason) {
            yield $reason => [$reason];
        }
    }

    public function test_expired_worker_cannot_publish_available_over_a_new_fence(): void
    {
        [, $document] = $this->quarantined();
        $runner = app(OperationRunner::class);
        $first = $runner->claim($document->scan_operation_id);
        self::assertNotNull($first);
        $oldWriter = app(ScanDocument::class)->execute($first);
        DB::table('async_operations')->where('id', $first->id)->update(['lease_expires_at' => now()->subSecond()]);
        $second = $runner->claim($first->id);
        self::assertNotNull($second);
        self::assertGreaterThan($first->fence, $second->fence);
        try {
            $runner->complete($first, $oldWriter);
            self::fail('Expired scanner published an outcome.');
        } catch (LostOperationLease) {
            $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'quarantined']);
        }
        $runner->complete($second, app(ScanDocument::class)->execute($second));
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.scan_passed')->count());
    }

    public function test_scan_finishing_after_deletion_cannot_revive_the_document(): void
    {
        [$owner, $document] = $this->quarantined();
        $runner = app(OperationRunner::class);
        $claim = $runner->claim($document->scan_operation_id);
        self::assertNotNull($claim);
        $writer = app(ScanDocument::class)->execute($claim);
        DB::transaction(fn () => app(DocumentService::class)->requestDeletion($owner, $document->id));
        $document->refresh();
        $runner->run($document->delete_operation_id);
        $runner->complete($claim, $writer);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'deleted']);
        self::assertSame([], $this->objects->objects);
        self::assertSame(0, DB::table('audit_events')->where('event_type', 'documents.scan_passed')->count());
    }

    public function test_delete_failure_retries_and_duplicate_delivery_is_safe(): void
    {
        [$owner, $document] = $this->quarantined();
        DB::transaction(fn () => app(DocumentService::class)->requestDeletion($owner, $document->id));
        $document->refresh();
        $this->objects->failDelete = true;
        app(OperationRunner::class)->run($document->delete_operation_id);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'deleting']);
        self::assertCount(1, $this->objects->objects);
        $this->objects->failDelete = false;
        $this->due($document->delete_operation_id);
        app(OperationRunner::class)->run($document->delete_operation_id);
        app(OperationRunner::class)->run($document->delete_operation_id);
        self::assertSame([], $this->objects->objects);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.deletion_completed')->count());
    }

    public function test_storage_delete_success_and_audit_failure_retries_absent_exact_version(): void
    {
        [$owner, $document] = $this->quarantined();
        DB::transaction(fn () => app(DocumentService::class)->requestDeletion($owner, $document->id));
        $document->refresh();
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_test_reject_audit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Simulated audit failure.' USING ERRCODE='23514'; END; $$;
            CREATE TRIGGER document_test_audit_failure BEFORE INSERT ON audit_events
                FOR EACH ROW WHEN (NEW.event_type='documents.deletion_completed') EXECUTE FUNCTION document_test_reject_audit();
            SQL);
        try {
            app(OperationRunner::class)->run($document->delete_operation_id);
            self::assertSame([], $this->objects->objects);
            $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'deleting']);
            $this->assertDatabaseHas('async_operations', ['id' => $document->delete_operation_id, 'state' => 'pending']);
        } finally {
            DB::unprepared('DROP TRIGGER document_test_audit_failure ON audit_events; DROP FUNCTION document_test_reject_audit()');
        }
        $this->due($document->delete_operation_id);
        app(OperationRunner::class)->run($document->delete_operation_id);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'deleted']);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.deletion_completed')->count());
    }

    public function test_download_grant_is_actor_version_and_expiry_bound_and_records_start_separately(): void
    {
        [$owner, $document] = $this->quarantined();
        app(OperationRunner::class)->run($document->scan_operation_id);
        $grant = DB::transaction(fn () => app(DocumentService::class)->authorizeDownload($owner, $document->id));
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.download_authorized')->count());
        self::assertSame(0, DB::table('audit_events')->where('event_type', 'documents.download_started')->count());
        $other = new DocumentOwner($owner->parentId, $owner->customerId, $owner->userId, (string) Str::uuid7(), $owner->requestId);
        foreach ([[$other, $grant], [$owner, new DocumentDownload($grant->document, $grant->object, $owner->actorId, now()->subSecond()->toImmutable())]] as [$actor, $invalid]) {
            try {
                DB::transaction(fn () => app(DocumentService::class)->consumeDownload($actor, $invalid));
                self::fail('Invalid download grant was consumed.');
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
            }
        }
        DB::transaction(fn () => app(DocumentService::class)->consumeDownload($owner, $grant));
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'documents.download_started')->count());
        DB::table('documents')->where('id', $document->id)->increment('lock_version');
        $this->expectException(HttpException::class);
        DB::transaction(fn () => app(DocumentService::class)->consumeDownload($owner, $grant));
    }
}
