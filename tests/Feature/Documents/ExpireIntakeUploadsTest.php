<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Application\Documents\ExpireIntakeUploads;
use App\Infrastructure\Async\OperationRunner;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Documents\Actions\ReconcileDocuments;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\StoredObject;
use App\Modules\Documents\Data\UploadReservation;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\IntakeDocuments;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Actions\TransitionRequest;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CommercialDatabase;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

final class ExpireIntakeUploadsTest extends TestCase
{
    use CommercialDatabase;
    use DocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeDocuments();
    }

    public function test_expired_actual_intake_reservation_detaches_atomically_and_late_put_is_orphan_cleaned(): void
    {
        [$user, $parent, $reservation] = $this->attachedReservation(25);
        $before = $parent->refresh()->lock_version;
        $this->assertDatabaseHas('intake_draft_documents', ['document_id' => $reservation->documentId]);

        self::assertSame(['inspected' => 1, 'expired' => 1], app(ExpireIntakeUploads::class)->handle());

        $this->assertDatabaseMissing('intake_draft_documents', ['document_id' => $reservation->documentId]);
        $this->assertDatabaseHas('documents', ['id' => $reservation->documentId, 'state' => 'deleting']);
        self::assertSame($before + 1, $parent->refresh()->lock_version);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'intake.document_expired', 'subject_id' => $parent->id, 'actor_id' => null]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'documents.deletion_requested', 'subject_id' => $reservation->documentId, 'actor_id' => null]);
        $operation = Document::query()->findOrFail($reservation->documentId)->delete_operation_id;
        self::assertIsString($operation);
        app(OperationRunner::class)->run($operation);
        $this->assertDatabaseHas('documents', ['id' => $reservation->documentId, 'state' => 'deleted', 'storage_version' => null]);

        // Model a remote PUT completing after the reservation was tombstoned.
        // It cannot finalize or be attached, and its own24h grace still applies.
        $stream = tmpfile();
        self::assertIsResource($stream);
        fwrite($stream, self::DOCUMENT_PDF);
        rewind($stream);
        try {
            $object = $this->objects->putIfAbsent($reservation->key, $stream, $reservation->bytes,
                $reservation->sha256, $reservation->format->mime());
        } finally {
            fclose($stream);
        }
        app(ReconcileDocuments::class)->handle();
        $this->assertDatabaseCount('document_orphan_objects', 0);
        self::assertCount(1, $this->objects->objects);
        $this->objects->objects[$object->key]['created'] = new DateTimeImmutable('-25 hours');
        app(ReconcileDocuments::class)->handle();
        $orphanOperation = DB::table('document_orphan_objects')->value('operation_id');
        self::assertIsString($orphanOperation);
        app(OperationRunner::class)->run($orphanOperation);
        self::assertSame([], $this->objects->objects);
        $this->assertDatabaseHas('document_orphan_objects', ['state' => 'deleted', 'storage_version' => $object->versionId]);
        self::assertSame(0, DB::table('intake_revision_documents')->where('document_id', $reservation->documentId)->count());
        self::assertTrue($user->refresh()->enabled);
    }

    public function test_expiry_cleans_closed_abandoned_amendment_without_mutating_submitted_history(): void
    {
        $user = $this->intakeCustomer();
        $parent = $this->createSubmitted($user);
        $actor = $this->intakeActor($user);
        $parent = app(ManageDraft::class)->amend($actor, $parent->id,
            VersionPrecondition::etag($parent->id, $parent->lock_version), (string) Str::uuid7());
        [, $parent, $reservation] = $this->attachedReservation(25, $user, $parent);
        $parent = app(TransitionRequest::class)->handle($actor, $parent->id,
            VersionPrecondition::etag($parent->id, $parent->lock_version), 'withdraw', 'Synthetic expiry test.', (string) Str::uuid7());
        $history = DB::table('request_revisions')->where('request_id', $parent->id)->get()->all();
        $this->assertDatabaseHas('request_drafts', ['request_id' => $parent->id, 'is_open' => false]);

        self::assertSame(['inspected' => 1, 'expired' => 1], app(ExpireIntakeUploads::class)->handle());

        $this->assertDatabaseMissing('intake_draft_documents', ['document_id' => $reservation->documentId]);
        $this->assertDatabaseHas('request_drafts', ['request_id' => $parent->id, 'is_open' => false]);
        $this->assertDatabaseHas('project_requests', ['id' => $parent->id, 'state' => 'withdrawn']);
        self::assertEquals($history, DB::table('request_revisions')->where('request_id', $parent->id)->get()->all());
    }

    public function test_grace_period_and_finalized_historical_attachments_are_preserved(): void
    {
        [, , $young] = $this->attachedReservation(23);
        [$user, $parent, $old] = $this->attachedReservation(25);
        $object = new StoredObject($old->key, 'retained-version', $old->bytes,
            $old->sha256, $old->format->mime());
        $actor = $this->intakeActor($user);
        $owner = app(IntakeDocuments::class)->owner($parent, $actor, (string) Str::uuid7());
        DB::transaction(fn () => app(DocumentService::class)->finalize($owner, $old->documentId, $object));
        $receipt = app(SubmitRequest::class)->handle($actor, $parent->id,
            VersionPrecondition::etag($parent->id, $parent->lock_version), (string) Str::uuid7(), (string) Str::uuid7());

        self::assertSame(['inspected' => 0, 'expired' => 0], app(ExpireIntakeUploads::class)->handle());
        app(ReconcileDocuments::class)->handle();

        $this->assertDatabaseHas('intake_draft_documents', ['document_id' => $young->documentId]);
        $this->assertDatabaseHas('documents', ['id' => $young->documentId, 'state' => 'uploading']);
        $this->assertDatabaseHas('intake_revision_documents', ['revision_id' => $receipt->revision_id, 'document_id' => $old->documentId]);
        $this->assertDatabaseHas('documents', ['id' => $old->documentId, 'state' => 'quarantined', 'storage_version' => 'retained-version']);
        self::assertSame(0, DB::table('async_operations')->where('kind', 'documents.delete')->count());
    }

    public function test_bound_cursor_progresses_wraps_and_repeated_expiry_is_a_noop(): void
    {
        $this->attachedReservation(25);
        $this->attachedReservation(25);
        $expiry = app(ExpireIntakeUploads::class);
        self::assertSame(['inspected' => 1, 'expired' => 1], $expiry->handle(1));
        self::assertSame(['inspected' => 1, 'expired' => 1], $expiry->handle(1));
        self::assertSame(['inspected' => 0, 'expired' => 0], $expiry->handle(1));
        self::assertNull(DB::table('document_reconciliation_cursors')->where('id', 1)->value('intake_upload_cursor'));
        self::assertSame(['inspected' => 0, 'expired' => 0], $expiry->handle(1));
        self::assertSame(2, DB::table('async_operations')->where('kind', 'documents.delete')->count());
        self::assertSame(2, DB::table('audit_events')->where('event_type', 'intake.document_expired')->count());
    }

    public function test_expiry_audit_failure_rolls_back_slot_parent_version_and_deletion_intent(): void
    {
        [, $parent, $reservation] = $this->attachedReservation(25);
        $version = $parent->lock_version;
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION test_reject_upload_expiry_audit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.event_type='intake.document_expired' THEN RAISE EXCEPTION 'test_expiry_rejection'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER test_reject_upload_expiry_audit BEFORE INSERT ON audit_events
                FOR EACH ROW EXECUTE FUNCTION test_reject_upload_expiry_audit();
            SQL);
        try {
            app(ExpireIntakeUploads::class)->handle();
            self::fail('Expiry ignored its required audit failure.');
        } catch (QueryException $exception) {
            self::assertSame('P0001', $exception->getCode());
        } finally {
            DB::unprepared('DROP TRIGGER test_reject_upload_expiry_audit ON audit_events; DROP FUNCTION test_reject_upload_expiry_audit()');
        }
        $this->assertDatabaseHas('intake_draft_documents', ['document_id' => $reservation->documentId]);
        $this->assertDatabaseHas('documents', ['id' => $reservation->documentId, 'state' => 'uploading']);
        self::assertSame($version, $parent->refresh()->lock_version);
        self::assertSame(0, DB::table('async_operations')->where('kind', 'documents.delete')->count());
        self::assertNull(DB::table('document_reconciliation_cursors')->where('id', 1)->value('intake_upload_cursor'));
    }

    /** @return array{User,ProjectRequest,UploadReservation} */
    private function attachedReservation(int $ageHours, ?User $user = null, ?ProjectRequest $parent = null): array
    {
        $user ??= $this->intakeCustomer();
        $actor = $this->intakeActor($user);
        $parent ??= app(ManageDraft::class)->create($actor, $this->intakeInput(), (string) Str::uuid7());
        // The same intake action used by the HTTP boundary creates the real
        // relational draft slot. Freeze only creation, not PostgreSQL's clock.
        Carbon::setTestNow(now()->subHours($ageHours));
        try {
            $reservation = DB::transaction(function () use ($actor, $parent): UploadReservation {
                $locked = app(IntakeStore::class)->find($actor, $parent->id, true);

                return app(IntakeDocuments::class)->reserve($locked, $actor,
                    VersionPrecondition::etag($locked->id, $locked->lock_version), 'abandoned.pdf',
                    strlen(self::DOCUMENT_PDF), hash('sha256', self::DOCUMENT_PDF), (string) Str::uuid7(), (string) Str::uuid7());
            });
        } finally {
            Carbon::setTestNow();
        }

        return [$user, $parent->refresh(), $reservation];
    }
}
