<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Infrastructure\Async\OperationRunner;
use App\Modules\Documents\Contracts\DocumentInspector;
use App\Modules\Documents\Contracts\MalwareScanner;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\DocumentFormat;
use App\Modules\Documents\Data\InspectionVerdict;
use App\Modules\Documents\Data\MalwareVerdict;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\CommercialDatabase;
use Tests\Support\DocumentMemoryStore;
use Tests\Support\IdentityHttp;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class DocumentHttpTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;
    use IntakeFixtures;

    private DocumentMemoryStore $objects;

    private const string PDF = "%PDF-1.4\n1 0 obj\n<</Type /Catalog>>\nendobj\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBrowser();
        $this->objects = new DocumentMemoryStore;
        app()->instance(PrivateObjectStore::class, $this->objects);
        app()->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(mixed $stream, int $size): MalwareVerdict
            {
                return MalwareVerdict::Clean;
            }
        });
        app()->instance(DocumentInspector::class, new class implements DocumentInspector
        {
            public function inspect(mixed $stream, int $size, DocumentFormat $format): InspectionVerdict
            {
                return new InspectionVerdict(true);
            }
        });
    }

    public function test_cookie_csrf_verification_parent_preconditions_and_field_allowlist(): void
    {
        $this->browser('POST', '/api/v1/project-requests/'.Str::uuid7().'/documents', $this->input())->assertUnauthorized();
        $user = $this->intakeCustomer(false);
        $this->signIn($user)->assertOk();
        $draft = $this->browser('POST', '/api/v1/project-requests', $this->intakeInput())->assertCreated();
        $path = '/api/v1/project-requests/'.$draft->json('data.id').'/documents';
        $headers = ['If-Match' => $draft->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()];
        $this->browser('POST', $path, $this->input(), $headers)->assertForbidden();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->browser('POST', $path, $this->input(), $headers, false)->assertForbidden();
        $this->browser('POST', $path, [...$this->input(), 'customer_id' => (string) Str::uuid7()], $headers)->assertUnprocessable();
        $this->browser('POST', $path, $this->input(), ['Idempotency-Key' => $headers['Idempotency-Key']])->assertStatus(428);
        $this->assertDatabaseCount('documents', 0);
        $this->browser('POST', $path, $this->input(), $headers)->assertCreated();
    }

    public function test_quarantine_duplicate_upload_scan_and_streamed_safe_download(): void
    {
        [$path, $etag, $id] = $this->reserve();
        $this->browser('GET', $path.'/'.$id.'/download')->assertConflict();
        $this->raw($path.'/'.$id.'/content', self::PDF, $etag)->assertOk()->assertJsonPath('data.state', 'quarantined');
        $this->raw($path.'/'.$id.'/content', self::PDF, $etag)->assertOk();
        $this->assertDatabaseCount('documents', 1);
        self::assertCount(1, $this->objects->objects);
        self::assertSame(1, DB::table('async_operations')->where('kind', 'documents.scan')->count());
        $this->browser('GET', $path.'/'.$id.'/download')->assertConflict();
        $this->scan($id);
        $download = $this->browser('GET', $path.'/'.$id.'/download')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
        self::assertSame(self::PDF, $download->streamedContent());
        self::assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $download->headers->get('Cache-Control'));
        self::assertStringContainsString('sandbox', $download->headers->get('Content-Security-Policy'));
        $download->assertHeader('Pragma', 'no-cache');
        self::assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $metadata = $this->browser('GET', $path.'/'.$id)->assertOk()->assertJsonPath('data.state', 'available');
        foreach (['storage_key', 'storage_version', 'customer_id', 'parent_id', 'sha256', 'scanner_output'] as $field) {
            self::assertArrayNotHasKey($field, $metadata->json('data'));
        }
        $this->assertDatabaseHas('audit_events', ['event_type' => 'documents.upload_completed', 'subject_id' => $id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'documents.download_authorized', 'subject_id' => $id]);
    }

    public function test_pending_scan_submission_and_amendment_replacement_preserve_history(): void
    {
        [$path, $etag, $id] = $this->reserve();
        $this->raw($path.'/'.$id.'/content', self::PDF, $etag)->assertOk();
        $parent = substr($path, 0, -strlen('/documents'));
        $submitted = $this->browser('POST', $parent.'/submissions', [], ['If-Match' => $etag, 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $revision = $submitted->json('data.revision_id');
        $this->browser('DELETE', $path.'/'.$id, [], ['If-Match' => $submitted->headers->get('ETag')])->assertConflict();
        $amendment = $this->browser('POST', $parent.'/amendments', [], ['If-Match' => $submitted->headers->get('ETag')])->assertOk();
        $new = $this->browser('POST', $path, $this->input(), ['If-Match' => $amendment->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $newId = $new->json('data.id');
        self::assertNotSame($id, $newId);
        $this->raw($path.'/'.$newId.'/content', self::PDF, $new->headers->get('ETag'))->assertOk();
        $this->browser('POST', $parent.'/submissions', [], ['If-Match' => $new->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->browser('GET', $parent.'/revisions/'.$revision)->assertOk()->assertJsonPath('data.document_id', $id);
        $this->assertDatabaseHas('documents', ['id' => $id, 'state' => 'quarantined']);
        $this->assertDatabaseCount('intake_revision_documents', 2);
        self::assertCount(2, $this->objects->objects);
    }

    public function test_draft_removal_and_replacement_create_tombstones_without_object_overwrite(): void
    {
        [$path, $etag, $old] = $this->reserve();
        $this->raw($path.'/'.$old.'/content', self::PDF, $etag)->assertOk();
        $next = $this->browser('POST', $path, $this->input(), ['If-Match' => $etag, 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->assertDatabaseHas('documents', ['id' => $old, 'state' => 'deleting']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'intake.document_replaced']);
        $this->browser('DELETE', $path.'/'.$next->json('data.id'), [], ['If-Match' => $etag])->assertStatus(412);
        $this->browser('DELETE', $path.'/'.$next->json('data.id'), [], ['If-Match' => $next->headers->get('ETag')])->assertOk()->assertJsonPath('data.state', 'deleting');
        $this->assertDatabaseCount('intake_draft_documents', 0);
    }

    public function test_customer_and_parent_isolation_for_metadata_bytes_upload_and_removal(): void
    {
        [$path, $etag, $id] = $this->reserve();
        $this->raw($path.'/'.$id.'/content', self::PDF, $etag)->assertOk();
        $this->scan($id);
        $sameOwnerDraft = $this->browser('POST', '/api/v1/project-requests', [])->assertCreated();
        $this->browser('GET', '/api/v1/project-requests/'.$sameOwnerDraft->json('data.id').'/documents/'.$id)->assertNotFound();
        $this->initializeBrowser();
        $this->signIn($this->intakeCustomer())->assertOk();
        foreach (['', '/download'] as $suffix) {
            $this->browser('GET', $path.'/'.$id.$suffix)->assertNotFound();
        }
        $this->browser('POST', $path, $this->input(), ['If-Match' => $etag, 'Idempotency-Key' => (string) Str::uuid7()])->assertNotFound();
        $this->raw($path.'/'.$id.'/content', self::PDF, $etag)->assertNotFound();
        $this->browser('DELETE', $path.'/'.$id, [], ['If-Match' => $etag])->assertNotFound();
    }

    #[DataProvider('invalidFiles')]
    public function test_layered_upload_validation_rejects_mismatch_and_size(string $filename, string $content, int $declaredBytes, string $sha, int $status): void
    {
        [$path, $etag, $id] = $this->reserve(['filename' => $filename, 'bytes' => $declaredBytes, 'sha256' => $sha]);
        $this->raw($path.'/'.$id.'/content', $content, $etag)->assertStatus($status);
        $this->assertDatabaseHas('documents', ['id' => $id, 'state' => 'uploading']);
        self::assertCount(0, $this->objects->objects);
    }

    public static function invalidFiles(): array
    {
        return [['wrong.docx', self::PDF, strlen(self::PDF), hash('sha256', self::PDF), 415],
            ['wrong.pdf', 'plain text', 10, hash('sha256', 'plain text'), 415],
            ['hash.pdf', self::PDF, strlen(self::PDF), str_repeat('a', 64), 422],
            ['short.pdf', self::PDF, strlen(self::PDF) + 1, hash('sha256', self::PDF), 422],
            ['long.pdf', self::PDF, 1, hash('sha256', self::PDF), 413]];
    }

    public function test_filename_is_display_only_and_reservation_replay_and_policy_are_bounded(): void
    {
        $user = $this->intakeCustomer();
        $this->signIn($user)->assertOk();
        $draft = $this->browser('POST', '/api/v1/project-requests', [])->assertCreated();
        $path = '/api/v1/project-requests/'.$draft->json('data.id').'/documents';
        $headers = ['If-Match' => $draft->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()];
        $this->browser('POST', $path, [...$this->input(), 'bytes' => 10485761], $headers)->assertUnprocessable();
        $this->browser('POST', $path, [...$this->input(), 'filename' => 'file.exe'], $headers)->assertStatus(415);
        $input = [...$this->input(), 'filename' => "../../folder\\private\r\n.pdf"];
        $first = $this->browser('POST', $path, $input, $headers)->assertCreated()->assertJsonPath('data.filename', 'private.pdf');
        $again = $this->browser('POST', $path, $input, $headers)->assertCreated();
        self::assertSame($first->json(), $again->json());
        self::assertSame($first->headers->get('ETag'), $again->headers->get('ETag'));
        $this->browser('POST', $path, $this->input(), $headers)->assertConflict();
        $this->assertDatabaseCount('documents', 1);
        $replacement = $this->browser('POST', $path, $this->input(), ['If-Match' => $first->headers->get('ETag'),
            'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->browser('POST', $path, $input, ['If-Match' => $replacement->headers->get('ETag'),
            'Idempotency-Key' => $headers['Idempotency-Key']])->assertConflict();
        $this->assertDatabaseHas('intake_draft_documents', ['document_id' => $replacement->json('data.id')]);
    }

    #[DataProvider('uploadRaces')]
    public function test_access_and_parent_version_are_rechecked_after_storage(string $change, int $status): void
    {
        [$path, $etag, $id] = $this->reserve();
        $this->objects->afterPut = function () use ($change, $id): void {
            self::assertSame(0, DB::transactionLevel());
            $doc = DB::table('documents')->where('id', $id)->sole();
            if ($change === 'parent') {
                DB::table('project_requests')->where('id', $doc->parent_id)->increment('lock_version');
            } else {
                DB::table('users')->where('id', $doc->customer_user_id)->update(['enabled' => false]);
            }
        };
        $this->raw($path.'/'.$id.'/content', self::PDF, $etag)->assertStatus($status);
        self::assertCount(1, $this->objects->objects);
        $this->assertDatabaseHas('documents', ['id' => $id, 'state' => 'uploading']);
        self::assertSame(0, DB::table('async_operations')->where('kind', 'documents.scan')->count());
    }

    public static function uploadRaces(): array
    {
        return [['parent', 412], ['identity', 401]];
    }

    public function test_download_reauthorizes_after_private_storage_io_and_emits_no_bytes_after_revocation(): void
    {
        [$path, $etag, $id] = $this->reserve();
        $this->raw($path.'/'.$id.'/content', self::PDF, $etag)->assertOk();
        $this->scan($id);
        $this->objects->afterOpen = function () use ($id): void {
            self::assertSame(0, DB::transactionLevel());
            DB::table('users')->where('id', DB::table('documents')->where('id', $id)->value('customer_user_id'))->increment('auth_version');
        };
        $response = $this->browser('GET', $path.'/'.$id.'/download')->assertUnauthorized();
        self::assertStringNotContainsString('%PDF', $response->getContent());
    }

    #[DataProvider('staffRoles')]
    public function test_staff_require_both_explicit_document_permission_and_parent_scope(string $role, bool $allowed): void
    {
        [$path, $etag, $id] = $this->reserve();
        $this->raw($path.'/'.$id.'/content', self::PDF, $etag)->assertOk();
        $this->scan($id);
        $parent = substr($path, 0, -strlen('/documents'));
        $submitted = $this->browser('POST', $parent.'/submissions', [], ['If-Match' => $etag, 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $staff = $this->intakeStaff($role);
        DB::table('project_requests')->where('id', $submitted->json('data.request_id'))->update(['assigned_staff_id' => $staff->id]);
        $this->initializeBrowser();
        $this->signInStaff($staff);
        $staffPath = str_replace('/api/v1/', '/api/v1/admin/', $path).'/'.$id;
        $this->browser('GET', $staffPath)->assertStatus($allowed ? 200 : 403);
        $response = $this->browser('GET', $staffPath.'/download')->assertStatus($allowed ? 200 : 403);
        if ($allowed) {
            self::assertSame(self::PDF, $response->streamedContent());
        }
        $this->browser('DELETE', $path.'/'.$id, [], ['If-Match' => $etag])->assertForbidden();
        if ($allowed) {
            DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('code', 'documents.download')->value('id'))->delete();
            $this->browser('GET', $staffPath.'/download')->assertForbidden();
        }
    }

    public static function staffRoles(): array
    {
        return [['super_admin', true], ['project_manager', true], ['business_analyst', true], ['reviewer', true],
            ['administrator', false], ['sales', false], ['support', false]];
    }

    public function test_upload_reservation_fails_closed_when_redis_is_unavailable(): void
    {
        $this->signIn($this->intakeCustomer())->assertOk();
        $draft = $this->browser('POST', '/api/v1/project-requests', [])->assertCreated();
        Config::set('database.redis.cache.host', '127.0.0.1');
        Config::set('database.redis.cache.port', 1);
        app('redis')->purge('cache');
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        $this->browser('POST', '/api/v1/project-requests/'.$draft->json('data.id').'/documents', $this->input(),
            ['If-Match' => $draft->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()])->assertStatus(503);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_malformed_document_ids_fail_before_uuid_database_queries(): void
    {
        [$path, $etag] = $this->reserve();
        foreach (['not-a-uuid', (string) Str::uuid()] as $invalid) {
            $this->browser('GET', $path.'/'.$invalid)->assertNotFound();
            $this->browser('GET', $path.'/'.$invalid.'/download')->assertNotFound();
            $this->browser('DELETE', $path.'/'.$invalid, [], ['If-Match' => $etag])->assertNotFound();
            $this->browser('POST', $path.'/'.$invalid.'/scan-retries', [], ['If-Match' => $etag])->assertNotFound();
        }
    }

    public function test_storage_outage_preserves_reservation_for_an_exact_retry(): void
    {
        [$path, $etag, $id] = $this->reserve();
        $this->objects->failPut = true;
        $this->raw($path.'/'.$id.'/content', self::PDF, $etag)->assertStatus(503);
        $this->assertDatabaseHas('documents', ['id' => $id, 'state' => 'uploading']);
        $this->objects->failPut = false;
        $this->raw($path.'/'.$id.'/content', self::PDF, $etag)->assertOk()->assertJsonPath('data.state', 'quarantined');
    }

    private function scan(string $id): void
    {
        $operationId = DB::table('documents')->where('id', $id)->value('scan_operation_id');
        app(OperationRunner::class)->run($operationId);
        $this->assertDatabaseHas('documents', ['id' => $id, 'state' => 'available']);
    }

    /** @param array<string,mixed>|null $input @return array{string,string,string} */
    private function reserve(?array $input = null): array
    {
        $this->signIn($this->intakeCustomer())->assertOk();
        $draft = $this->browser('POST', '/api/v1/project-requests', $this->intakeInput())->assertCreated();
        $path = '/api/v1/project-requests/'.$draft->json('data.id').'/documents';
        $response = $this->browser('POST', $path, $input ?? $this->input(), ['If-Match' => $draft->headers->get('ETag'),
            'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $response->assertHeader('Location', $path.'/'.$response->json('data.id'));

        return [$path, $response->headers->get('ETag'), $response->json('data.id')];
    }

    /** @return array{filename:string,bytes:int,sha256:string} */
    private function input(): array
    {
        return ['filename' => 'brief.pdf', 'bytes' => strlen(self::PDF), 'sha256' => hash('sha256', self::PDF)];
    }

    private function raw(string $path, string $content, string $etag): TestResponse
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

    private function signInStaff(User $user): void
    {
        $this->signIn($user)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }
}
