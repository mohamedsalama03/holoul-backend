<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use App\Application\Documents\ExpireGuestDocuments;
use App\Infrastructure\Async\OperationRunner;
use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Documents\Contracts\DocumentInspector;
use App\Modules\Documents\Contracts\MalwareScanner;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\DocumentFormat;
use App\Modules\Documents\Data\InspectionVerdict;
use App\Modules\Documents\Data\MalwareVerdict;
use App\Modules\ProjectIntake\Models\GuestAccess;
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

final class GuestIntakeHttpTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;
    use IntakeFixtures;

    private DocumentMemoryStore $objects;

    protected function setUp(): void
    {
        parent::setUp();
        // Unique test-only limiter namespace; exercise the real fail-closed Redis limiter.
        Config::set('database.redis.options.prefix', 'g1_test_'.Str::uuid7().'_');
        app('redis')->purge('cache');
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
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

    public function test_guest_submission_has_canonical_snapshot_no_account_and_exact_replay(): void
    {
        Config::set('ai.enabled', false);
        [$id, $headers] = $this->draft();
        $input = $this->input();
        $receipt = $this->submit($id, $headers, $input)->assertCreated()->assertJsonPath('data.next_step', 'sign_in_verify_email_and_claim');
        self::assertSame($receipt->json(), $this->submit($id, $headers, $input)->assertCreated()->json());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('request_revisions', 1);
        $this->assertDatabaseHas('project_requests', ['id' => $id, 'guest_origin' => true, 'customer_id' => null, 'state' => 'submitted']);
        $this->assertDatabaseHas('request_revisions', ['request_id' => $id, 'full_name' => 'Guest Person', 'email' => 'guest@example.test',
            'phone_e164' => '+218912345678', 'submitted_by' => null, 'customer_id' => null, 'budget_minor' => 1234567, 'currency' => 'LYD', 'provenance' => 'guest_submission']);
        foreach (['guest_submitted', 'claim_issued'] as $event) {
            $this->assertDatabaseHas('audit_events', ['event_type' => 'intake.'.$event, 'subject_id' => $id]);
        }
        $persisted = (array) DB::table('intake_guest_access')->where('request_id', $id)->first();
        self::assertNotContains($headers['X-Intake-Capability'], $persisted);
        self::assertNotContains($receipt->json('data.claim_token'), $persisted);
        $audit = json_encode(DB::table('audit_events')->get());
        foreach ([$receipt->json('data.claim_token'), $input['email'], $input['project_description'], $input['phone']] as $secret) {
            self::assertStringNotContainsString($secret, $audit);
        }
        $this->submit($id, [...$headers, 'Idempotency-Key' => (string) Str::uuid7()], $input)->assertConflict();
        $this->submit($id, $headers, [...$input, 'project_name' => 'Different'])->assertConflict();
    }

    public function test_capability_is_bound_to_browser_and_does_not_grant_private_reads(): void
    {
        [$id, $headers] = $this->draft();
        $input = $this->input();
        $this->submit($id, [...$headers, 'X-Intake-Capability' => str_repeat('a', 64)], $input)->assertNotFound();
        $receipt = $this->submit($id, $headers, $input)->assertCreated();
        $reference = $receipt->json('data.reference');
        foreach (['/api/v1/project-requests/'.$id, '/api/v1/project-requests/by-reference/'.$reference,
            '/api/v1/project-requests/by-reference/'.$reference.'?email=guest@example.test'] as $path) {
            $this->browser('GET', $path)->assertUnauthorized();
        }
        $this->browser('GET', '/api/v1/guest/project-requests/'.$id, [], $headers)->assertNotFound();
        $this->initializeBrowser();
        $this->submit($id, $headers, $input)->assertNotFound();
    }

    public function test_exact_origin_csrf_and_authenticated_persona_are_enforced(): void
    {
        $this->browser('POST', '/api/v1/guest/project-requests', [], [], false)->assertForbidden();
        $this->browser('POST', '/api/v1/guest/project-requests', [], ['Origin' => 'https://evil.example'])->assertForbidden();
        $this->browser('POST', '/api/v1/guest/project-requests', ['customer_id' => (string) Str::uuid7()])->assertUnprocessable();
        $this->signIn($this->intakeCustomer())->assertOk();
        $this->browser('POST', '/api/v1/guest/project-requests')->assertForbidden();
        $this->assertDatabaseCount('project_requests', 0);
        $this->initializeBrowser();
        $this->signIn($this->intakeStaff())->assertAccepted();
        $this->browser('POST', '/api/v1/guest/project-requests')->assertForbidden();
    }

    public function test_authenticated_intake_uses_current_profile_and_incomplete_profile_is_explicit(): void
    {
        $user = $this->intakeCustomer();
        $this->signIn($user)->assertOk();
        $draft = $this->browser('POST', '/api/v1/project-requests', $this->intakeInput())->assertCreated();
        $user->forceFill(['full_name' => 'Current server profile'])->save();
        DB::table('customers')->where('user_id', $user->id)->update(['phone_e164' => '+12025550199', 'phone_display' => '+12025550199']);
        $headers = ['If-Match' => $draft->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()];
        $path = '/api/v1/project-requests/'.$draft->json('data.id').'/submissions';
        $this->browser('POST', $path, ['full_name' => 'Forged', 'email' => 'forged@example.test', 'customer_id' => (string) Str::uuid7()], $headers)->assertUnprocessable();
        $this->browser('POST', $path, [], $headers)->assertCreated();
        $this->assertDatabaseHas('request_revisions', ['request_id' => $draft->json('data.id'), 'full_name' => 'Current server profile',
            'email' => $user->email, 'phone_e164' => '+12025550199']);
        $reader = \Mockery::mock(CustomerContactReader::class);
        $reader->shouldReceive('currentForIdentity')->andReturnNull();
        app()->instance(CustomerContactReader::class, $reader);
        $this->browser('POST', '/api/v1/project-requests', [])->assertUnprocessable()->assertJsonPath('error.fields.profile_complete_required.0', 'This field is invalid.');
        $this->browser('POST', '/api/v1/guest/project-requests')->assertForbidden();
    }

    public function test_oversized_payload_invalid_identifiers_and_unapproved_guest_ai_fail_closed(): void
    {
        [$id, $headers] = $this->draft();
        $this->submit($id, $headers, ['project_description' => str_repeat('x', 131073)])->assertStatus(413);
        $this->browser('GET', '/api/v1/guest/project-requests/'.$id.'/documents/not-a-uuid', [], $headers)->assertNotFound();
        $this->browser('POST', '/api/v1/ai-runs', [])->assertUnauthorized();
        $this->browser('POST', '/api/v1/guest/ai-runs', [])->assertNotFound();
        $this->assertDatabaseCount('request_revisions', 0);
    }

    public function test_expired_unsubmitted_guest_attachments_are_deleted_but_submitted_history_is_retained(): void
    {
        [$id, $headers] = $this->draft();
        $bytes = "%PDF-1.4\n%%EOF\n";
        $path = '/api/v1/guest/project-requests/'.$id.'/documents';
        $reservation = $this->browser('POST', $path, ['filename' => 'abandoned.pdf', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], $headers)->assertCreated();
        $headers['If-Match'] = $reservation->headers->get('ETag');
        $document = $reservation->json('data.id');
        $this->raw($path.'/'.$document.'/content', $bytes, $headers)->assertOk();
        self::assertSame(0, app(ExpireGuestDocuments::class)->handle(20));
        DB::table('project_requests')->where('id', $id)->update(['created_at' => now()->subDays(2)]);
        GuestAccess::query()->whereKey($id)->update(['created_at' => now()->subDays(2), 'expires_at' => now()->subDays(2)->addMinutes(30)]);
        self::assertSame(1, app(ExpireGuestDocuments::class)->handle(20));
        $this->assertDatabaseHas('documents', ['id' => $document, 'state' => 'deleting']);
        $this->assertDatabaseCount('intake_draft_documents', 0);
        self::assertSame(0, app(ExpireGuestDocuments::class)->handle(20));
    }

    public function test_abuse_limits_and_outages_prevent_draft_writes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->browser('POST', '/api/v1/guest/project-requests')->assertCreated();
        }
        $this->browser('POST', '/api/v1/guest/project-requests')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('project_requests', 5);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'intake.guest_throttled']);
        Config::set('database.redis.cache.host', '127.0.0.1');
        Config::set('database.redis.cache.port', 1);
        app('redis')->purge('cache');
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        $this->browser('POST', '/api/v1/guest/project-requests')->assertStatus(503);
        $this->assertDatabaseCount('project_requests', 5);
    }

    #[DataProvider('invalidInput')]
    public function test_guest_validation_rejects_bad_contact_budget_and_forgery(array $patch): void
    {
        [$id, $headers] = $this->draft();
        $this->submit($id, $headers, array_replace($this->input(), $patch))->assertUnprocessable();
        $this->assertDatabaseCount('request_revisions', 0);
    }

    public static function invalidInput(): array
    {
        return [[['full_name' => ' ']], [['email' => 'invalid']], [['phone' => '0912345678']], [['phone' => '+2181']],
            [['phone' => '+12025550199 ext 12']], [['customer_id' => 'forged']], [['customer_user_id' => 'forged']],
            [['claim_token' => 'forged']], [['document_id' => (string) Str::uuid7()]], [['estimated_budget' => 1.0]],
            [['currency' => 'USD', 'estimated_budget' => '1.001']], [['estimated_budget' => '1.0001']],
            [['estimated_budget' => '-1']], [['currency' => 'EUR']], [['budget_unknown' => true]],
            [['project_description' => str_repeat('x', 20001)]]];
    }

    #[DataProvider('budgets')]
    public function test_both_currencies_zero_and_unknown_use_the_existing_exact_money_model(array $budget, ?int $minor): void
    {
        [$id, $headers] = $this->draft();
        $this->submit($id, $headers, array_replace($this->input(), $budget))->assertCreated();
        self::assertSame($minor, DB::table('request_revisions')->where('request_id', $id)->value('budget_minor'));
    }

    public static function budgets(): array
    {
        return [[['currency' => 'USD', 'estimated_budget' => '10.20'], 1020], [['currency' => 'LYD', 'estimated_budget' => '0'], 0],
            [['currency' => 'USD', 'estimated_budget' => '0.00'], 0], [['budget_unknown' => true, 'estimated_budget' => null, 'currency' => null], null]];
    }

    public function test_public_taxonomy_reuses_active_categories_and_rejects_inactive_or_mismatched_selection(): void
    {
        $input = $this->input();
        $this->browser('GET', '/api/v1/intake/categories')->assertOk()->assertJsonPath('data.0.id', $input['category_id']);
        $this->browser('GET', '/api/v1/intake/categories/'.$input['category_id'].'/subcategories')->assertOk()->assertJsonPath('data.0.id', $input['subcategory_id']);
        [$id, $headers] = $this->draft();
        $other = $this->intakeTaxonomy();
        $this->submit($id, $headers, [...$input, 'subcategory_id' => $other['subcategory_id']])->assertUnprocessable();
        DB::table('categories')->where('id', $input['category_id'])->update(['active' => false, 'lock_version' => DB::raw('lock_version + 1')]);
        $this->submit($id, $headers, $input)->assertUnprocessable();
    }

    public function test_email_equality_never_claims_and_all_guests_receive_identical_continuation(): void
    {
        $existing = $this->intakeCustomer();
        [$one, $headers] = $this->draft();
        $a = $this->submit($one, $headers, [...$this->input(), 'email' => $existing->email])->assertCreated();
        [$two, $otherHeaders] = $this->draft();
        $b = $this->submit($two, $otherHeaders, $this->input())->assertCreated();
        self::assertSame(array_keys($a->json('data')), array_keys($b->json('data')));
        self::assertSame($a->json('data.next_step'), $b->json('data.next_step'));
        $this->assertDatabaseHas('project_requests', ['id' => $one, 'customer_id' => null]);
        $this->assertDatabaseCount('users', 1);
        $this->browser('GET', '/api/v1/intake/account-exists?email='.$existing->email)->assertNotFound();
    }

    public function test_one_browser_cannot_reuse_its_final_submission_key_on_a_second_draft(): void
    {
        [$one, $headers] = $this->draft();
        [$two, $other] = $this->draft();
        $input = $this->input();
        $this->submit($one, $headers, $input)->assertCreated();
        $this->submit($two, [...$other, 'Idempotency-Key' => $headers['Idempotency-Key']], $input)->assertConflict();
        $this->assertDatabaseCount('request_revisions', 1);
    }

    public function test_claim_requires_verified_matching_customer_and_token_preserves_history_and_is_idempotent(): void
    {
        $user = $this->intakeCustomer();
        $other = $this->intakeCustomer();
        [$id, $headers] = $this->draft();
        $token = $this->submit($id, $headers, [...$this->input(), 'email' => $user->email])->assertCreated()->json('data.claim_token');
        $snapshot = (array) DB::table('request_revisions')->where('request_id', $id)->first();
        $key = ['Idempotency-Key' => (string) Str::uuid7()];
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], $key)->assertUnauthorized();
        $this->signIn($other)->assertOk();
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], $key)->assertNotFound();
        $this->initializeBrowser();
        $this->signIn($user)->assertOk();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], $key)->assertNotFound();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token, 'customer_id' => $other->id], $key)->assertUnprocessable();
        $claim = $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], $key)->assertOk();
        self::assertSame($claim->json(), $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], $key)->assertOk()->json());
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], ['Idempotency-Key' => (string) Str::uuid7()])->assertNotFound();
        $this->browser('GET', '/api/v1/project-requests/'.$id)->assertOk();
        $user->forceFill(['full_name' => 'Later profile name'])->save();
        self::assertSame($snapshot, (array) DB::table('request_revisions')->where('request_id', $id)->first());
        $this->assertDatabaseCount('intake_guest_claims', 1);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'intake.claim_completed')->count());
        $this->browser('POST', '/api/v1/project-requests/'.$id.'/amendments', [], ['If-Match' => $claim->headers->get('ETag')])->assertOk();
        $view = $this->browser('GET', '/api/v1/project-requests/'.$id)->assertOk();
        $this->browser('POST', '/api/v1/project-requests/'.$id.'/submissions', [], ['If-Match' => $view->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->assertDatabaseHas('request_revisions', ['request_id' => $id, 'revision_number' => 2, 'full_name' => 'Later profile name', 'submitted_by' => $user->id]);
    }

    public function test_expired_claim_and_expired_draft_fail_without_private_data(): void
    {
        $user = $this->intakeCustomer();
        [$id, $headers] = $this->draft();
        $token = $this->submit($id, $headers, [...$this->input(), 'email' => $user->email])->assertCreated()->json('data.claim_token');
        GuestAccess::query()->whereKey($id)->update(['claim_expires_at' => now()->subSecond()]);
        $this->signIn($user)->assertOk();
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], ['Idempotency-Key' => (string) Str::uuid7()])->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['event_type' => 'intake.claim_expired']);
        $this->initializeBrowser();
        [$draft, $proof] = $this->draft();
        GuestAccess::query()->whereKey($draft)->update(['created_at' => now()->subHour(), 'expires_at' => now()->subMinutes(30)]);
        $this->submit($draft, $proof, $this->input())->assertNotFound();
    }

    public function test_authorized_staff_can_review_guest_history_but_cannot_advance_unclaimed_work(): void
    {
        $user = $this->intakeCustomer();
        [$id, $headers] = $this->draft();
        $token = $this->submit($id, $headers, [...$this->input(), 'email' => $user->email])->assertCreated()->json('data.claim_token');
        $this->signIn($this->intakeStaff('super_admin'))->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
        $path = '/api/v1/admin/project-requests/'.$id;
        $detail = $this->browser('GET', $path)->assertOk()->assertJsonPath('data.latest_revision.provenance', 'guest_submission')
            ->assertJsonPath('data.latest_revision.submitted_by', null);
        $this->browser('GET', '/api/v1/admin/project-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->browser('GET', $path.'/revisions')->assertOk()->assertJsonCount(1, 'data');
        $this->browser('GET', $path.'/history')->assertOk()->assertJsonPath('data.0.actor_id', null);
        $review = $this->browser('POST', $path.'/reviews', [], ['If-Match' => $detail->headers->get('ETag')])->assertOk();
        $etag = ['If-Match' => $review->headers->get('ETag')];
        $this->browser('POST', $path.'/discovery-handoffs', [], $etag)->assertConflict();
        $this->browser('POST', $path.'/information-requests', ['message' => 'Please clarify.'], $etag)->assertConflict();
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], ['Idempotency-Key' => (string) Str::uuid7()])->assertForbidden();
        $this->browser('POST', $path.'/rejections', ['message' => 'Outside current service scope.'], $etag)->assertOk();
        $this->initializeBrowser();
        $this->signIn($user)->assertOk();
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], ['Idempotency-Key' => (string) Str::uuid7()])->assertNotFound();
        $this->assertDatabaseCount('intake_guest_claims', 0);
        $this->assertDatabaseHas('project_requests', ['id' => $id, 'state' => 'rejected', 'customer_id' => null]);
    }

    #[DataProvider('formats')]
    public function test_guest_document_upload_quarantine_claim_and_private_download(string $format): void
    {
        $user = $this->intakeCustomer();
        [$id, $headers] = $this->draft();
        $bytes = $format === 'pdf' ? "%PDF-1.4\n1 0 obj\n<</Type /Catalog>>\nendobj\n%%EOF\n" : $this->docx();
        $path = '/api/v1/guest/project-requests/'.$id.'/documents';
        $reservation = $this->browser('POST', $path, ['filename' => 'brief.'.$format, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], $headers)->assertCreated();
        $document = $reservation->json('data.id');
        $headers['If-Match'] = $reservation->headers->get('ETag');
        $this->browser('GET', $path.'/'.$document, [], $headers)->assertOk();
        $this->raw($path.'/'.$document.'/content', $bytes, $headers)->assertOk()->assertJsonPath('data.state', 'quarantined');
        $this->browser('GET', $path.'/'.$document.'/download', [], $headers)->assertNotFound();
        $before = DB::table('documents')->where('id', $document)->first();
        $token = $this->submit($id, $headers, [...$this->input(), 'email' => $user->email])->assertCreated()->json('data.claim_token');
        $this->browser('GET', $path.'/'.$document, [], $headers)->assertNotFound();
        DB::table('project_requests')->where('id', $id)->update(['created_at' => now()->subDays(2)]);
        self::assertSame(0, app(ExpireGuestDocuments::class)->handle(20));
        $this->signIn($user)->assertOk();
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], ['Idempotency-Key' => (string) Str::uuid7()])->assertOk();
        $private = '/api/v1/project-requests/'.$id.'/documents/'.$document;
        $this->browser('GET', $private.'/download')->assertConflict();
        app(OperationRunner::class)->run($before->scan_operation_id);
        $download = $this->browser('GET', $private.'/download')->assertOk();
        self::assertSame($bytes, $download->streamedContent());
        $after = DB::table('documents')->where('id', $document)->first();
        foreach (['storage_key', 'storage_version', 'expected_sha256', 'uploader_id'] as $field) {
            self::assertSame($before->$field, $after->$field);
        }
        self::assertSame($user->id, $after->customer_user_id);
        $this->assertDatabaseHas('intake_revision_documents', ['document_id' => $document, 'customer_id' => null]);
        $this->initializeBrowser();
        $this->signIn($this->intakeCustomer())->assertOk();
        $this->browser('GET', $private.'/download')->assertNotFound();
    }

    public static function formats(): array
    {
        return [['pdf'], ['docx']];
    }

    public function test_cross_guest_document_attack_and_upload_expiry_recheck(): void
    {
        [$id, $headers] = $this->draft();
        $bytes = "%PDF-1.4\n%%EOF\n";
        $path = '/api/v1/guest/project-requests/'.$id.'/documents';
        $reserved = $this->browser('POST', $path, ['filename' => 'a.pdf', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], $headers)->assertCreated();
        $headers['If-Match'] = $reserved->headers->get('ETag');
        $document = $reserved->json('data.id');
        [$other, $otherHeaders] = $this->draft();
        $this->browser('GET', '/api/v1/guest/project-requests/'.$other.'/documents/'.$document, [], $otherHeaders)->assertNotFound();
        $this->objects->afterPut = function () use ($id): void {
            GuestAccess::query()->whereKey($id)->update(['created_at' => now()->subHour(), 'expires_at' => now()->subMinutes(30)]);
        };
        $this->raw($path.'/'.$document.'/content', $bytes, $headers)->assertNotFound();
        $this->assertDatabaseHas('documents', ['id' => $document, 'state' => 'uploading', 'storage_version' => null]);
    }

    public function test_guest_documents_reject_unsupported_and_oversized_content_without_storage(): void
    {
        [$id, $headers] = $this->draft();
        $path = '/api/v1/guest/project-requests/'.$id.'/documents';
        $input = ['filename' => 'brief.exe', 'bytes' => 10, 'sha256' => str_repeat('a', 64)];
        $this->browser('POST', $path, $input, $headers)->assertStatus(415);
        $this->browser('POST', $path, [...$input, 'filename' => 'brief.pdf', 'bytes' => 10485761], $headers)->assertUnprocessable();
        $reserved = $this->browser('POST', $path, [...$input, 'filename' => 'brief.pdf'], $headers)->assertCreated();
        $headers['If-Match'] = $reserved->headers->get('ETag');
        $this->raw($path.'/'.$reserved->json('data.id').'/content', str_repeat('x', 10485761), $headers)->assertStatus(413);
        $this->assertDatabaseHas('documents', ['id' => $reserved->json('data.id'), 'state' => 'uploading', 'storage_version' => null]);
    }

    #[DataProvider('customerQuota')]
    public function test_claim_respects_customer_document_quota_and_rolls_back_the_entire_association(bool $byteQuota): void
    {
        $user = $this->intakeCustomer();
        $owned = $this->createSubmitted($user);
        $rows = [];
        // Valid tombstones awaiting deletion still reserve bytes/count under B4.
        $count = $byteQuota ? 103 : 1000;
        for ($i = 0; $i < $count; $i++) {
            $documentId = (string) Str::uuid7();
            $rows[] = ['id' => $documentId, 'customer_id' => $owned->customer_id, 'customer_user_id' => $user->id,
                'uploader_id' => $user->id, 'parent_id' => $owned->id, 'reservation_key_hash' => hash('sha256', $documentId),
                'reservation_input_hash' => str_repeat('b', 64), 'display_name' => 'reserved.pdf', 'format' => 'pdf',
                'expected_size' => $byteQuota ? ($i < 102 ? 10485760 : 4194304) : 1, 'expected_sha256' => str_repeat('c', 64),
                'storage_key' => 'quarantine/'.$documentId, 'state' => 'deleting', 'upload_expires_at' => now()->addMinutes(10)];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('documents')->insert($chunk);
        }
        [$id, $headers] = $this->draft();
        $bytes = "%PDF-1.4\n%%EOF\n";
        $path = '/api/v1/guest/project-requests/'.$id.'/documents';
        $reserved = $this->browser('POST', $path, ['filename' => 'brief.pdf', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], $headers)->assertCreated();
        $headers['If-Match'] = $reserved->headers->get('ETag');
        $document = $reserved->json('data.id');
        $this->raw($path.'/'.$document.'/content', $bytes, $headers)->assertOk();
        $token = $this->submit($id, $headers, [...$this->input(), 'email' => $user->email])->assertCreated()->json('data.claim_token');
        $this->signIn($user)->assertOk();
        $key = ['Idempotency-Key' => (string) Str::uuid7()];
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], $key)->assertStatus(429);
        $this->assertDatabaseHas('project_requests', ['id' => $id, 'customer_id' => null, 'lock_version' => 3]);
        $this->assertDatabaseHas('documents', ['id' => $document, 'customer_id' => null]);
        $this->assertDatabaseCount('intake_guest_claims', 0);
        self::assertSame(0, DB::table('audit_events')->where('event_type', 'intake.claim_completed')->count());
        DB::table('documents')->where('parent_id', $owned->id)->update(['state' => 'deleted', 'deleted_at' => now(), 'lock_version' => 2]);
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], $key)->assertOk();
    }

    public static function customerQuota(): array
    {
        return [[true], [false]];
    }

    private function draft(): array
    {
        $created = $this->browser('POST', '/api/v1/guest/project-requests')->assertCreated();

        return [$created->json('data.draft_id'), ['If-Match' => $created->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7(),
            'X-Intake-Capability' => $created->json('data.capability')]];
    }

    private function input(): array
    {
        return [...$this->intakeInput(), 'full_name' => 'Guest Person', 'email' => ' Guest@EXAMPLE.TEST ', 'phone' => '+218 91 234 5678'];
    }

    private function submit(string $id, array $headers, array $input): TestResponse
    {
        return $this->browser('POST', '/api/v1/guest/project-requests/'.$id.'/submissions', $input, $headers);
    }

    private function raw(string $path, string $content, array $headers): TestResponse
    {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'localhost:8443', 'SERVER_PORT' => '8443', 'REMOTE_ADDR' => $this->browserIp,
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ORIGIN' => 'https://localhost:8443',
            'HTTP_X_XSRF_TOKEN' => $this->browserCookies['XSRF-TOKEN']];
        foreach ($headers as $key => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
        }

        return $this->call('PUT', 'https://localhost:8443'.$path, [], $this->browserCookies, [], $server, $content);
    }

    private function docx(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'g1-docx-');
        $zip = new \ZipArchive;
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Guest brief</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $bytes = file_get_contents($file);
        unlink($file);

        return $bytes;
    }
}
