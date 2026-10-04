<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Application\Projects\ExpireProjectUploads;
use App\Infrastructure\Async\OperationRunner;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Documents\Actions\ReconcileDocuments;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\UploadReservation;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Actions\ProjectDocuments;
use App\Modules\Projects\Actions\ProjectLifecycle;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommercialDatabase;
use Tests\Support\DocumentFixtures;
use Tests\Support\IdentityHttp;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class ProjectDocumentsTest extends TestCase
{
    use CommercialDatabase;
    use DocumentFixtures;
    use IdentityHttp;
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBrowser();
        $this->initializeDocuments();
    }

    public function test_upload_policy_blocks_staff_reservation_and_old_content_without_affecting_staff_capabilities(): void
    {
        $fixture = $this->projectFixture();
        $this->documentStaffSignIn($fixture['author']);
        [$path, $etag, $id] = $this->reserveProjectDocument($fixture['project']);
        $before = $this->browser('GET', '/api/v1/identity/me')->assertOk()->json('data.capabilities');
        Config::set('documents.uploads_enabled', false);
        self::assertSame($before, $this->browser('GET', '/api/v1/identity/me')->assertOk()->json('data.capabilities'));
        self::assertNotContains('project_requests.documents.upload', $before);
        $this->browser('POST', $path, $this->documentInput(),
            ['If-Match' => $etag, 'Idempotency-Key' => (string) Str::uuid7()])->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
        $this->rawDocument($path.'/'.$id.'/content', self::DOCUMENT_PDF, $etag)->assertServiceUnavailable();
        $this->assertDatabaseHas('documents', ['id' => $id, 'state' => 'uploading', 'storage_version' => null]);
        self::assertSame([], $this->objects->objects);
        self::assertSame(0, DB::table('async_operations')->where('kind', 'documents.scan')->count());
        Config::set('documents.uploads_enabled', true);
        $this->rawDocument($path.'/'.$id.'/content', self::DOCUMENT_PDF, $etag)->assertOk()->assertJsonPath('data.state', 'quarantined');
    }

    public function test_staff_uploads_use_b4_quarantine_and_only_cleared_customer_visible_bytes_reach_the_owner(): void
    {
        $fixture = $this->projectFixture();
        $this->documentStaffSignIn($fixture['author']);
        [$path, $etag, $id] = $this->reserveProjectDocument($fixture['project']);
        $this->rawDocument($path.'/'.$id.'/content', self::DOCUMENT_PDF, $etag)->assertOk()->assertJsonPath('data.state', 'quarantined');
        $this->assertDatabaseHas('project_documents', ['project_id' => $fixture['project']->id, 'document_id' => $id, 'visibility' => 'customer']);
        $this->assertDatabaseMissing('project_document_uploads', ['document_id' => $id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'projects.document_attached', 'subject_id' => $fixture['project']->id]);
        $own = '/api/v1/projects/'.$fixture['project']->id.'/documents/'.$id;
        $this->initializeBrowser();
        $this->signIn($fixture['customer'])->assertOk();
        $this->browser('GET', $own.'/download')->assertConflict();
        $this->scanProjectDocument($id);
        $response = $this->browser('GET', $own.'/download')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        self::assertSame(self::DOCUMENT_PDF, $response->streamedContent());
        self::assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_events', ['event_type' => 'documents.download_started', 'subject_id' => $id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'projects.document_accessed', 'subject_id' => $fixture['project']->id]);
        $metadata = $this->browser('GET', $own)->assertOk()->json('data');
        self::assertSame(['id', 'filename', 'format', 'mime', 'bytes', 'state', 'version', 'retryable', 'visibility'], array_keys($metadata));
        $this->initializeBrowser();
        $this->signIn($this->intakeCustomer())->assertOk();
        $this->browser('GET', $own)->assertNotFound();
        $this->browser('GET', $own.'/download')->assertNotFound();
        $this->browser('GET', '/api/v1/projects/'.$fixture['project']->id.'/documents')->assertNotFound();
    }

    public function test_internal_documents_and_pending_uploads_are_excluded_from_customer_metadata_lists_and_downloads(): void
    {
        $fixture = $this->projectFixture();
        $this->documentStaffSignIn($fixture['author']);
        [$path, $etag, $internal] = $this->reserveProjectDocument($fixture['project'], 'internal');
        $this->rawDocument($path.'/'.$internal.'/content', self::DOCUMENT_PDF, $etag)->assertOk();
        $this->scanProjectDocument($internal);
        [, , $pending] = $this->reserveProjectDocument($fixture['project']);
        $this->browser('GET', $path)->assertOk()->assertJsonCount(2, 'data');
        $this->initializeBrowser();
        $this->signIn($fixture['customer'])->assertOk();
        $own = '/api/v1/projects/'.$fixture['project']->id.'/documents';
        $this->browser('GET', $own)->assertOk()->assertJsonCount(0, 'data');
        foreach ([$internal, $pending] as $id) {
            $this->browser('GET', $own.'/'.$id)->assertNotFound();
            $this->browser('GET', $own.'/'.$id.'/download')->assertNotFound();
        }
        $this->browser('POST', $path, $this->documentInput(), ['If-Match' => $etag, 'Idempotency-Key' => (string) Str::uuid7()])->assertForbidden();
    }

    public function test_reservation_replay_checks_visibility_and_stale_or_forged_input_cannot_create_documents(): void
    {
        $fixture = $this->projectFixture();
        $project = $fixture['project'];
        $this->documentStaffSignIn($fixture['author']);
        $path = '/api/v1/admin/projects/'.$project->id.'/documents';
        $headers = ['If-Match' => VersionPrecondition::etag($project->id, $project->lock_version), 'Idempotency-Key' => (string) Str::uuid7()];
        $first = $this->browser('POST', $path, $this->documentInput(), $headers)->assertCreated();
        $this->browser('POST', $path, $this->documentInput(), $headers)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->browser('POST', $path, $this->documentInput('internal'), $headers)->assertConflict();
        $this->browser('POST', $path, $this->documentInput(), [...$headers, 'Idempotency-Key' => (string) Str::uuid7()])->assertStatus(412);
        $this->browser('POST', $path, [...$this->documentInput(), 'customer_id' => (string) Str::uuid7()], $headers)->assertUnprocessable();
        $this->browser('POST', $path, [...$this->documentInput(), 'document_id' => (string) Str::uuid7()], $headers)->assertUnprocessable();
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('project_document_uploads', 1);
        $this->browser('DELETE', $path.'/'.$first->json('data.id'), [], ['If-Match' => $first->headers->get('ETag')])->assertOk();
        $this->browser('POST', $path, $this->documentInput(), $headers)->assertConflict();
        $this->assertDatabaseHas('documents', ['id' => $first->json('data.id'), 'state' => 'deleting']);
    }

    public function test_download_rechecks_persisted_session_after_storage_io_before_streaming_any_byte(): void
    {
        $fixture = $this->projectFixture();
        $this->documentStaffSignIn($fixture['author']);
        [$path, $etag, $id] = $this->reserveProjectDocument($fixture['project']);
        $this->rawDocument($path.'/'.$id.'/content', self::DOCUMENT_PDF, $etag)->assertOk();
        $this->scanProjectDocument($id);
        $this->initializeBrowser();
        $this->signIn($fixture['customer'])->assertOk();
        $this->objects->afterOpen = function () use ($fixture): void {
            self::assertSame(0, DB::transactionLevel());
            DB::table('identity_sessions')->where('user_id', $fixture['customer']->id)->delete();
        };
        $this->browser('GET', '/api/v1/projects/'.$fixture['project']->id.'/documents/'.$id.'/download')->assertUnauthorized();
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'documents.download_started', 'subject_id' => $id]);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'projects.document_accessed', 'subject_id' => $fixture['project']->id]);
    }

    public function test_upload_revalidates_the_locked_parent_after_storage_io_and_a_fresh_exact_retry_can_finalize(): void
    {
        $fixture = $this->projectFixture();
        $this->documentStaffSignIn($fixture['author']);
        [$path, $etag, $id] = $this->reserveProjectDocument($fixture['project']);
        $this->objects->afterPut = function () use ($fixture): void {
            self::assertSame(0, DB::transactionLevel());
            DB::transaction(function () use ($fixture): void {
                $project = Project::query()->whereKey($fixture['project']->id)->lockForUpdate()->firstOrFail();
                app(ProjectStore::class)->changed($project);
            });
        };
        $this->rawDocument($path.'/'.$id.'/content', self::DOCUMENT_PDF, $etag)->assertStatus(412);
        $this->assertDatabaseHas('documents', ['id' => $id, 'state' => 'uploading', 'storage_version' => null]);
        $this->assertDatabaseCount('project_documents', 0);
        $this->objects->afterPut = null;
        $project = $fixture['project']->refresh();
        $this->rawDocument($path.'/'.$id.'/content', self::DOCUMENT_PDF, VersionPrecondition::etag($project->id, $project->lock_version))->assertOk();
        $this->assertDatabaseHas('documents', ['id' => $id, 'state' => 'quarantined']);
        self::assertCount(1, $this->objects->objects);
    }

    public function test_database_constraints_enforce_exact_parent_customer_and_immutable_retained_visibility(): void
    {
        $fixture = $this->projectFixture();
        $foreign = $this->projectFixture();
        $this->documentStaffSignIn($fixture['author']);
        [$path, $etag, $id] = $this->reserveProjectDocument($fixture['project']);
        $base = ['document_id' => $id, 'project_id' => $foreign['project']->id, 'customer_id' => $foreign['project']->customer_id,
            'visibility' => 'customer', 'attached_by' => $fixture['author']->id];
        $this->deniedSql(fn () => DB::table('project_documents')->insert($base));
        $this->deniedSql(fn () => DB::table('project_document_uploads')->where('document_id', $id)->update(['visibility' => 'internal']));
        $this->rawDocument($path.'/'.$id.'/content', self::DOCUMENT_PDF, $etag)->assertOk();
        $this->deniedSql(fn () => DB::table('project_documents')->where('document_id', $id)->update(['visibility' => 'internal']));
        $this->deniedSql(fn () => DB::table('project_documents')->where('document_id', $id)->delete());
        $this->deniedSql(fn () => DB::statement('TRUNCATE project_documents'));
        $this->deniedSql(fn () => DB::table('documents')->where('id', $id)->update(['state' => 'deleting', 'lock_version' => 3]));
        $owner = new DocumentOwner($fixture['project']->id, $fixture['project']->customer_id, $fixture['customer']->id,
            $fixture['author']->id, (string) Str::uuid7());
        $unlinked = DB::transaction(fn () => app(DocumentService::class)->reserve($owner, 'ownership.pdf', strlen(self::DOCUMENT_PDF),
            hash('sha256', self::DOCUMENT_PDF), (string) Str::uuid7()));
        try {
            DB::transaction(fn () => DB::table('project_document_uploads')->insert([...$base, 'document_id' => $unlinked->documentId]));
            self::fail('The composite document parent/customer foreign key was bypassed.');
        } catch (QueryException $e) {
            self::assertSame('23503', $e->getCode());
        }
        $wrongParent = new DocumentOwner((string) Str::uuid7(), $owner->customerId, $owner->userId, $owner->actorId, $owner->requestId);
        $sameCustomer = DB::transaction(fn () => app(DocumentService::class)->reserve($wrongParent, 'other-project.pdf', strlen(self::DOCUMENT_PDF),
            hash('sha256', self::DOCUMENT_PDF), (string) Str::uuid7()));
        try {
            DB::transaction(fn () => DB::table('project_document_uploads')->insert(['document_id' => $sameCustomer->documentId,
                'project_id' => $fixture['project']->id, 'customer_id' => $fixture['project']->customer_id,
                'visibility' => 'customer', 'attached_by' => $fixture['author']->id]));
            self::fail('Matching customer alone allowed a foreign parent attachment.');
        } catch (QueryException $e) {
            self::assertSame('23503', $e->getCode());
        }
        try {
            DB::transaction(fn () => app(DocumentService::class)->requestDeletion($owner, $id));
            self::fail('Retained project document deletion was accepted.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        $this->browser('DELETE', $path.'/'.$id, [], ['If-Match' => $etag])->assertConflict();
        $this->assertDatabaseHas('project_documents', ['document_id' => $id, 'visibility' => 'customer']);
        $this->assertDatabaseHas('documents', ['id' => $id, 'state' => 'quarantined']);
    }

    public function test_project_uploads_share_b4_customer_quota_and_staff_permission_revocation_is_current(): void
    {
        $fixture = $this->projectFixture();
        $this->documentStaffSignIn($fixture['author']);
        $this->reserveProjectDocument($fixture['project']);
        $this->reserveProjectDocument($fixture['project']);
        $project = $fixture['project']->refresh();
        $path = '/api/v1/admin/projects/'.$project->id.'/documents';
        $this->browser('POST', $path, $this->documentInput(), ['If-Match' => VersionPrecondition::etag($project->id, $project->lock_version),
            'Idempotency-Key' => (string) Str::uuid7()])->assertTooManyRequests();
        $this->assertDatabaseCount('documents', 2);
        $this->assertDatabaseCount('document_quotas', 1);
        DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('code', 'projects.documents.read')->value('id'))->delete();
        $this->browser('GET', $path)->assertForbidden();
    }

    public function test_bounded_expiry_releases_abandoned_project_reservations_and_never_prunes_retained_documents(): void
    {
        $fixture = $this->projectFixture();
        $actor = $this->documentActor($fixture['author']);
        Carbon::setTestNow(now()->subHours(25));
        try {
            $reservation = DB::transaction(function () use ($actor, $fixture): UploadReservation {
                $project = app(ProjectStore::class)->find($actor, $fixture['project']->id);

                return app(ProjectDocuments::class)->reserve($project, $actor, VersionPrecondition::etag($project->id, $project->lock_version),
                    'abandoned.pdf', strlen(self::DOCUMENT_PDF), hash('sha256', self::DOCUMENT_PDF), 'internal', (string) Str::uuid7(), (string) Str::uuid7());
            });
        } finally {
            Carbon::setTestNow();
        }
        self::assertSame(['inspected' => 1, 'expired' => 1], app(ExpireProjectUploads::class)->handle(1));
        $this->assertDatabaseMissing('project_document_uploads', ['document_id' => $reservation->documentId]);
        $this->assertDatabaseHas('documents', ['id' => $reservation->documentId, 'state' => 'deleting']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'projects.document_upload_expired', 'actor_id' => null]);
        self::assertSame(['inspected' => 0, 'expired' => 0], app(ExpireProjectUploads::class)->handle(1));
        $this->documentStaffSignIn($fixture['author']);
        [$path, $etag, $retained] = $this->reserveProjectDocument($fixture['project']);
        $this->rawDocument($path.'/'.$retained.'/content', self::DOCUMENT_PDF, $etag)->assertOk();
        app(ReconcileDocuments::class)->handle();
        $this->assertDatabaseHas('project_documents', ['document_id' => $retained]);
        $this->assertDatabaseHas('documents', ['id' => $retained, 'state' => 'quarantined']);
    }

    public function test_terminal_projects_preserve_document_reads_and_only_expire_unfinished_reservations(): void
    {
        $fixture = $this->projectFixture();
        $this->documentStaffSignIn($fixture['author']);
        [$path, $etag, $id] = $this->reserveProjectDocument($fixture['project']);
        $this->rawDocument($path.'/'.$id.'/content', self::DOCUMENT_PDF, $etag)->assertOk();
        $this->scanProjectDocument($id);
        $actor = $this->documentActor($fixture['author']);
        Carbon::setTestNow(now()->subHours(25));
        try {
            $pending = DB::transaction(function () use ($actor, $fixture): UploadReservation {
                $project = app(ProjectStore::class)->find($actor, $fixture['project']->id);

                return app(ProjectDocuments::class)->reserve($project, $actor, VersionPrecondition::etag($project->id, $project->lock_version),
                    'abandoned.pdf', strlen(self::DOCUMENT_PDF), hash('sha256', self::DOCUMENT_PDF), 'internal', (string) Str::uuid7(), (string) Str::uuid7());
            });
        } finally {
            Carbon::setTestNow();
        }
        $project = DB::transaction(function () use ($actor, $fixture): Project {
            $project = app(ProjectStore::class)->find($actor, $fixture['project']->id);

            return app(ProjectLifecycle::class)->cancel($actor, $project, ['reason' => 'Delivery cancelled.',
                'customer_communication' => 'Cancellation communicated to the customer.'], (string) Str::uuid7());
        });
        $version = $project->lock_version;
        $headers = ['If-Match' => VersionPrecondition::etag($project->id, $version), 'Idempotency-Key' => (string) Str::uuid7()];
        $this->browser('POST', $path, $this->documentInput(), $headers)->assertConflict();
        $this->rawDocument($path.'/'.$pending->documentId.'/content', self::DOCUMENT_PDF, $headers['If-Match'])->assertConflict();
        self::assertSame(['inspected' => 1, 'expired' => 1], app(ExpireProjectUploads::class)->handle());
        self::assertSame($version, $project->refresh()->lock_version);
        self::assertSame('cancelled', $project->state);
        $this->initializeBrowser();
        $this->signIn($fixture['customer'])->assertOk();
        self::assertSame(self::DOCUMENT_PDF, $this->browser('GET', '/api/v1/projects/'.$project->id.'/documents/'.$id.'/download')->assertOk()->streamedContent());
        $this->assertDatabaseHas('project_documents', ['document_id' => $id]);
        $this->assertDatabaseMissing('project_document_uploads', ['document_id' => $pending->documentId]);
    }

    private function deniedSql(callable $action): void
    {
        try {
            DB::transaction($action);
            self::fail('The database accepted an invalid attachment mutation.');
        } catch (QueryException $e) {
            self::assertContains($e->getCode(), ['23514', '23503', '55000']);
        }
    }

    private function documentActor(User $user): ProjectActor
    {
        $actor = $this->intakeActor($user);

        return new ProjectActor($actor->id, $actor->customerId, $actor->verifiedEmail, true, $actor->permissions);
    }

    /** @return array{filename:string,bytes:int,sha256:string,visibility:string} */
    private function documentInput(string $visibility = 'customer'): array
    {
        return ['filename' => 'delivery.pdf', 'bytes' => strlen(self::DOCUMENT_PDF), 'sha256' => hash('sha256', self::DOCUMENT_PDF), 'visibility' => $visibility];
    }

    /** @return array{string,string,string} */
    private function reserveProjectDocument(Project $project, string $visibility = 'customer'): array
    {
        $project->refresh();
        $path = '/api/v1/admin/projects/'.$project->id.'/documents';
        $response = $this->browser('POST', $path, $this->documentInput($visibility),
            ['If-Match' => VersionPrecondition::etag($project->id, $project->lock_version), 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $response->assertHeader('Location', $path.'/'.$response->json('data.id'));

        return [$path, $response->headers->get('ETag'), $response->json('data.id')];
    }

    private function scanProjectDocument(string $id): void
    {
        app(OperationRunner::class)->run(Document::query()->findOrFail($id)->scan_operation_id);
        $this->assertDatabaseHas('documents', ['id' => $id, 'state' => 'available']);
    }

    private function documentStaffSignIn(User $user): void
    {
        $this->signIn($user)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }

    private function rawDocument(string $path, string $content, string $etag): TestResponse
    {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost:8443', 'SERVER_PORT' => '8443', 'REMOTE_ADDR' => $this->browserIp,
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ORIGIN' => 'https://localhost:8443',
            'HTTP_X_XSRF_TOKEN' => $this->browserCookies['XSRF-TOKEN'], 'HTTP_IF_MATCH' => $etag];
        $response = $this->call('PUT', 'https://localhost:8443'.$path, [], $this->browserCookies, [], $server, $content);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->browserCookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }
}
