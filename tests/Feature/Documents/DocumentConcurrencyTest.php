<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommercialDatabase;
use Tests\Support\DocumentFixtures;
use Tests\TestCase;

final class DocumentConcurrencyTest extends TestCase
{
    use CommercialDatabase;
    use DocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeDocuments();
    }

    public function test_independent_reservations_serialize_on_customer_quota_and_recheck_after_commit(): void
    {
        $owner = $this->documentOwner();
        $this->reservation($owner);
        $primary = DB::getDefaultConnection();
        Config::set('database.connections.document_peer', Config::array('database.connections.'.$primary));
        DB::connection('document_peer')->statement("SET lock_timeout = '100ms'");
        DB::beginTransaction();
        try {
            $this->reservation($owner);
            DB::setDefaultConnection('document_peer');
            try {
                $this->reservation($owner);
                self::fail('Independent reservation bypassed quota serialization.');
            } catch (QueryException $exception) {
                self::assertSame('55P03', $exception->getCode());
            } finally {
                DB::setDefaultConnection($primary);
            }
            DB::commit();
            DB::setDefaultConnection('document_peer');
            try {
                $this->reservation($owner);
                self::fail('Committed quota was not rechecked.');
            } catch (HttpException $exception) {
                self::assertSame(429, $exception->getStatusCode());
            }
        } finally {
            DB::setDefaultConnection($primary);
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('document_peer');
        }
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_delete_claim_blocks_competing_attachment_then_attachment_rejects_after_commit(): void
    {
        [$owner, $document] = $this->quarantined();
        Config::set('database.connections.document_peer', Config::array('database.connections.'.DB::getDefaultConnection()));
        $peer = DB::connection('document_peer');
        $peer->statement("SET lock_timeout = '100ms'");
        $attachment = ['draft_id' => DB::table('request_drafts')->where('request_id', $owner->parentId)->value('id'),
            'request_id' => $owner->parentId, 'customer_id' => $owner->customerId, 'document_id' => $document->id];
        DB::beginTransaction();
        try {
            app(DocumentService::class)->requestDeletion($owner, $document->id);
            try {
                $peer->table('intake_draft_documents')->insert($attachment);
                self::fail('Attachment bypassed the deletion row lock.');
            } catch (QueryException $exception) {
                self::assertSame('55P03', $exception->getCode());
            }
            DB::commit();
            try {
                $peer->table('intake_draft_documents')->insert($attachment);
                self::fail('A deleting document acquired an attachment.');
            } catch (QueryException $exception) {
                self::assertSame('23514', $exception->getCode());
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('document_peer');
        }
        $this->assertDatabaseCount('intake_draft_documents', 0);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'deleting']);
    }

    public function test_actual_submission_serializes_competing_deletion_and_retains_the_immutable_attachment(): void
    {
        [$owner, $document] = $this->quarantined();
        $actor = $this->intakeActor(User::query()->findOrFail($owner->userId));
        $request = ProjectRequest::query()->findOrFail($owner->parentId);
        $request = app(ManageDraft::class)->update($actor, $request->id, VersionPrecondition::etag($request->id, $request->lock_version),
            $this->intakeInput(), (string) Str::uuid7());
        DB::table('intake_draft_documents')->insert(['draft_id' => DB::table('request_drafts')->where('request_id', $request->id)->value('id'),
            'request_id' => $request->id, 'customer_id' => $owner->customerId, 'document_id' => $document->id]);
        $primary = DB::getDefaultConnection();
        Config::set('database.connections.document_peer', Config::array('database.connections.'.$primary));
        DB::connection('document_peer')->statement("SET lock_timeout = '100ms'");
        DB::beginTransaction();
        try {
            app(SubmitRequest::class)->handle($actor, $request->id, VersionPrecondition::etag($request->id, $request->lock_version),
                (string) Str::uuid7(), (string) Str::uuid7());
            DB::setDefaultConnection('document_peer');
            try {
                DB::transaction(fn () => app(DocumentService::class)->requestDeletion($owner, $document->id));
                self::fail('Deletion bypassed the submission document lock.');
            } catch (QueryException $exception) {
                self::assertSame('55P03', $exception->getCode());
            } finally {
                DB::setDefaultConnection($primary);
            }
            DB::commit();
            DB::setDefaultConnection('document_peer');
            try {
                DB::transaction(fn () => app(DocumentService::class)->requestDeletion($owner, $document->id));
                self::fail('Committed revision attachment was not rechecked.');
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
            }
        } finally {
            DB::setDefaultConnection($primary);
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('document_peer');
        }
        $this->assertDatabaseHas('intake_revision_documents', ['request_id' => $request->id, 'document_id' => $document->id]);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'quarantined', 'storage_version' => $document->storage_version]);
        $this->assertDatabaseCount('request_revisions', 1);
        self::assertArrayHasKey($document->storage_key, $this->objects->objects);
    }
}
