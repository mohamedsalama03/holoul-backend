<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Infrastructure\Async\OperationRunner;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\AssignRequest;
use App\Modules\ProjectIntake\Actions\IntakeDocuments;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Actions\TransitionRequest;
use App\Modules\Proposals\Models\Proposal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\CommercialDatabase;
use Tests\Support\CommercialFixtures;
use Tests\Support\DocumentFixtures;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

final class ProposalDocumentsTest extends TestCase
{
    use CommercialDatabase;
    use CommercialFixtures;
    use DocumentFixtures;
    use IdentityHttp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBrowser();
        $this->initializeDocuments();
    }

    public function test_only_cleared_historical_b4_documents_can_attach_and_issued_attachment_is_immutable(): void
    {
        [$f,$document] = $this->documentFixture();
        $baseline = $this->completeDiscovery($f['author'], $f['request']);
        $created = $this->commercialCommand($f['author'], $f['request'], 'proposal.create', input: $this->commercialTerms($baseline));
        $id = $created['data']['id'];
        $this->signInStaff($f['author']);
        $path = '/api/v1/admin/project-requests/'.$f['request']->id.'/proposals/'.$id.'/documents';
        $this->browser('POST', $path, ['document_id' => $document->id], $this->headers($f))->assertConflict();
        app(OperationRunner::class)->run($document->scan_operation_id);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'state' => 'available']);
        $this->commercialCommand($f['approver'], $f['request'], 'proposal.approve', $id);
        $attached = $this->browser('POST', $path, ['document_id' => $document->id], $this->headers($f))->assertOk();
        self::assertSame('draft', Proposal::query()->findOrFail($id)->state);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'proposals.approval_invalidated', 'subject_id' => $id]);
        $this->initializeBrowser();
        $this->signIn($f['customer'])->assertOk();
        $own = '/api/v1/project-requests/'.$f['request']->id.'/proposals/'.$id.'/documents/'.$document->id;
        $this->browser('GET', $own.'/download')->assertNotFound();
        $this->commercialCommand($f['approver'], $f['request'], 'proposal.approve', $id);
        $this->commercialCommand($f['author'], $f['request'], 'proposal.issue', $id);
        $response = $this->browser('GET', $own.'/download')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        self::assertSame(self::DOCUMENT_PDF, $response->streamedContent());
        self::assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_events', ['event_type' => 'documents.download_started', 'subject_id' => $document->id]);
        $this->initializeBrowser();
        $this->signIn($this->intakeCustomer())->assertOk();
        $this->browser('GET', $own)->assertNotFound();
        $this->browser('GET', $own.'/download')->assertNotFound();
        try {
            DB::transaction(fn () => DB::table('proposal_documents')->where('proposal_id', $id)->delete());
            self::fail('Issued attachment was mutable.');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode());
        }
    }

    public function test_document_download_rechecks_revoked_session_after_storage_io(): void
    {
        [$f,$document] = $this->documentFixture();
        app(OperationRunner::class)->run($document->scan_operation_id);
        $baseline = $this->completeDiscovery($f['author'], $f['request']);
        $created = $this->commercialCommand($f['author'], $f['request'], 'proposal.create', input: $this->commercialTerms($baseline));
        $id = $created['data']['id'];
        $this->commercialCommand($f['author'], $f['request'], 'proposal.attach_document', $id, ['document_id' => $document->id]);
        $this->commercialCommand($f['approver'], $f['request'], 'proposal.approve', $id);
        $this->commercialCommand($f['author'], $f['request'], 'proposal.issue', $id);
        $this->signIn($f['customer'])->assertOk();
        $this->objects->afterOpen = function () use ($f): void {
            self::assertSame(0, DB::transactionLevel(), 'Storage must run outside a database transaction.');
            DB::table('identity_sessions')->where('user_id', $f['customer']->id)->delete();
        };
        $this->browser('GET', '/api/v1/project-requests/'.$f['request']->id.'/proposals/'.$id.'/documents/'.$document->id.'/download')->assertUnauthorized();
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'documents.download_started', 'subject_id' => $document->id]);
    }

    public function test_foreign_document_and_staff_without_explicit_document_permission_are_denied(): void
    {
        [$f,$document] = $this->documentFixture();
        app(OperationRunner::class)->run($document->scan_operation_id);
        [$other,$foreign] = $this->documentFixture();
        app(OperationRunner::class)->run($foreign->scan_operation_id);
        $baseline = $this->completeDiscovery($f['author'], $f['request']);
        $created = $this->commercialCommand($f['author'], $f['request'], 'proposal.create', input: $this->commercialTerms($baseline));
        $id = $created['data']['id'];
        $this->signInStaff($f['author']);
        $base = '/api/v1/admin/project-requests/'.$f['request']->id.'/proposals/'.$id.'/documents';
        $this->browser('POST', $base, ['document_id' => $foreign->id], $this->headers($f))->assertNotFound();
        $this->commercialCommand($f['author'], $f['request'], 'proposal.attach_document', $id, ['document_id' => $document->id]);
        $sales = $this->intakeStaff('sales');
        app(AssignRequest::class)->handle($this->intakeActor($f['author']), $f['request']->id, $this->commercialEtag($f['request']->refresh()), $sales->id, (string) Str::uuid7());
        $this->initializeBrowser();
        $this->signInStaff($sales);
        $this->browser('GET', $base.'/'.$document->id)->assertForbidden();
        $this->browser('GET', $base.'/'.$document->id.'/download')->assertForbidden();
    }

    private function documentFixture(): array
    {
        $customer = $this->intakeCustomer();
        $author = $this->intakeStaff('super_admin');
        $approver = $this->intakeStaff('super_admin');
        $actor = $this->intakeActor($customer);
        $request = app(ManageDraft::class)->create($actor, $this->intakeInput(), (string) Str::uuid7());
        $reservation = DB::transaction(function () use ($request, $actor) {
            $record = app(IntakeStore::class)->find($actor, $request->id, true);

            return app(IntakeDocuments::class)->reserve($record, $actor, $this->commercialEtag($record), 'brief.pdf', strlen(self::DOCUMENT_PDF), hash('sha256', self::DOCUMENT_PDF), (string) Str::uuid7(), (string) Str::uuid7());
        });
        $owner = new DocumentOwner($request->id, $request->customer_id, $customer->id, $customer->id, (string) Str::uuid7());
        $object = $this->uploadFixture($reservation);
        DB::transaction(fn () => app(DocumentService::class)->finalize($owner, $reservation->documentId, $object));
        app(SubmitRequest::class)->handle($actor, $request->id, $this->commercialEtag($request->refresh()), (string) Str::uuid7(), (string) Str::uuid7());
        app(AssignRequest::class)->handle($this->intakeActor($author), $request->id, $this->commercialEtag($request->refresh()), $author->id, (string) Str::uuid7());
        foreach (['review', 'discovery'] as $action) {
            app(TransitionRequest::class)->handle($this->intakeActor($author), $request->id, $this->commercialEtag($request->refresh()), $action, null, (string) Str::uuid7());
        }

        return [['customer' => $customer, 'author' => $author, 'approver' => $approver, 'request' => $request->refresh()], Document::query()->findOrFail($reservation->documentId)];
    }

    private function headers(array $f): array
    {
        return ['If-Match' => $this->commercialEtag($f['request']->refresh()), 'Idempotency-Key' => (string) Str::uuid7()];
    }

    private function signInStaff(User $user): void
    {
        $this->signIn($user)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }
}
