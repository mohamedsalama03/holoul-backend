<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\IdentityHttp;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class IntakeHttpTest extends TestCase
{
    use DatabaseMigrations;
    use IdentityHttp;
    use IntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBrowser();
    }

    public function test_authentication_csrf_and_exact_origin_remain_mandatory(): void
    {
        $this->browser('GET', '/api/v1/categories')->assertUnauthorized();
        $user = $this->intakeCustomer();
        $this->signIn($user)->assertOk();
        $this->browser('POST', '/api/v1/project-requests', [], [], false)->assertForbidden()->assertHeader('X-Request-ID');
        $this->browser('POST', '/api/v1/project-requests', [], ['Origin' => 'https://evil.example'])->assertForbidden();
        $this->assertDatabaseCount('project_requests', 0);
    }

    public function test_draft_api_uses_versioned_ownership_and_only_verified_accounts_submit(): void
    {
        $user = $this->intakeCustomer(false);
        $this->signIn($user)->assertOk();
        $created = $this->browser('POST', '/api/v1/project-requests', [])->assertCreated()->assertHeader('Location');
        $id = $created->json('data.id');
        $etag = $created->headers->get('ETag');
        $this->browser('GET', '/api/v1/project-requests/'.$id)->assertOk()->assertJsonPath('data.draft.project_name', null);
        $this->browser('PATCH', '/api/v1/project-requests/'.$id.'/draft', ['project_name' => 'Draft'])->assertStatus(428)->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');
        $updated = $this->browser('PATCH', '/api/v1/project-requests/'.$id.'/draft', $this->intakeInput(), ['If-Match' => $etag])->assertOk()->assertHeaderMissing('Location');
        $this->browser('PATCH', '/api/v1/project-requests/'.$id.'/draft', ['project_name' => 'Stale'], ['If-Match' => $etag])->assertStatus(412);
        $this->browser('POST', '/api/v1/project-requests/'.$id.'/submissions', [], ['If-Match' => $updated->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()])->assertForbidden();
        $this->assertDatabaseCount('request_revisions', 0);
    }

    public function test_exact_lyd_submission_replay_and_revision_snapshot_are_exposed_safely(): void
    {
        $user = $this->intakeCustomer();
        $this->signIn($user)->assertOk();
        $created = $this->browser('POST', '/api/v1/project-requests', $this->intakeInput())->assertCreated();
        $id = $created->json('data.id');
        $headers = ['If-Match' => $created->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()];
        $first = $this->browser('POST', '/api/v1/project-requests/'.$id.'/submissions', [], $headers)->assertCreated();
        $replay = $this->browser('POST', '/api/v1/project-requests/'.$id.'/submissions', [], $headers)->assertCreated();
        self::assertSame($first->json(), $replay->json());
        self::assertSame($first->headers->get('ETag'), $replay->headers->get('ETag'));
        $view = $this->browser('GET', '/api/v1/project-requests/'.$id)->assertOk()->assertJsonPath('data.latest_revision.estimated_budget', '1234.567')
            ->assertJsonPath('data.latest_revision.currency', 'LYD')->assertJsonPath('data.latest_revision.full_name', 'عميل Test');
        self::assertArrayNotHasKey('customer_id', $view->json('data'));
        self::assertArrayNotHasKey('budget_minor', $view->json('data.latest_revision'));
        $reference = $first->json('data.reference');
        $this->browser('GET', '/api/v1/project-requests/by-reference/'.$reference)->assertOk()->assertJsonPath('data.id', $id);
        $this->browser('POST', '/api/v1/project-requests/'.$id.'/submissions', [], ['If-Match' => $first->headers->get('ETag'), 'Idempotency-Key' => $headers['Idempotency-Key']])->assertConflict();
        $this->assertDatabaseCount('request_revisions', 1);
        $this->assertDatabaseCount('intake_notification_intents', 1);
    }

    #[DataProvider('forgedFields')]
    public function test_forged_ownership_and_workflow_fields_are_rejected(string $field, mixed $value): void
    {
        $this->signIn($this->intakeCustomer())->assertOk();
        $this->browser('POST', '/api/v1/project-requests', [$field => $value])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('project_requests', 0);
    }

    public static function forgedFields(): array
    {
        return [['customer_id', 'other'], ['customer_user_id', 'other'], ['state', 'submitted'], ['reference', 'REQ-2026-99999'],
            ['assigned_staff_id', 'staff'], ['full_name', 'Forged'], ['email', 'forged@example.test'], ['phone', '+12025550199'],
            ['role', 'super_admin'], ['budget_minor', 123], ['lock_version', 1]];
    }

    #[DataProvider('invalidBudgets')]
    public function test_api_rejects_float_excess_precision_and_inconsistent_budget(mixed $amount, mixed $unknown, mixed $currency): void
    {
        $this->signIn($this->intakeCustomer())->assertOk();
        $this->browser('POST', '/api/v1/project-requests', ['budget_unknown' => $unknown, 'estimated_budget' => $amount, 'currency' => $currency])->assertUnprocessable();
    }

    public static function invalidBudgets(): array
    {
        return [[1.25, false, 'USD'], ['1.001', false, 'USD'], ['1.0001', false, 'LYD'], ['-1.00', false, 'USD'],
            ['1.00', true, 'USD'], ['1.00', null, 'USD'], ['1.00', false, 'EUR'], [null, false, 'USD'],
            ['1.00', 'false', 'USD'], ['92233720368547758.08', false, 'USD']];
    }

    #[DataProvider('foreignPaths')]
    public function test_customer_b_never_observes_customer_a_through_any_lookup(string $path): void
    {
        $owner = $this->intakeCustomer();
        $foreign = $this->createSubmitted($owner);
        $other = $this->intakeCustomer();
        $this->signIn($other)->assertOk();
        $path = strtr($path, ['{id}' => $foreign->id, '{reference}' => $foreign->reference, '{revision}' => $foreign->latest_revision_id, '{customer}' => $foreign->customer_id]);
        $this->browser('GET', '/api/v1/'.$path)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->browser('GET', '/api/v1/project-requests?reference='.$foreign->reference)->assertOk()->assertJsonCount(0, 'data');
        $this->browser('GET', '/api/v1/project-requests?q=Learning')->assertOk()->assertJsonCount(0, 'data');
        $this->browser('PATCH', '/api/v1/project-requests/'.$foreign->id.'/draft', ['project_name' => 'Forged'], ['If-Match' => VersionPrecondition::etag($foreign->id, $foreign->lock_version)])->assertNotFound();
    }

    public static function foreignPaths(): array
    {
        return [['project-requests/{id}'], ['project-requests/by-reference/{reference}'], ['customers/{customer}/project-requests/{id}'],
            ['project-requests/{id}/revisions'], ['project-requests/{id}/revisions/{revision}'],
            ['project-requests/{id}/information-requests'], ['project-requests/{id}/history']];
    }

    public function test_nested_and_revision_parents_and_malformed_ids_are_checked_before_lookup(): void
    {
        $user = $this->intakeCustomer();
        $a = $this->createSubmitted($user);
        $b = $this->createSubmitted($user);
        $this->signIn($user)->assertOk();
        $this->browser('GET', '/api/v1/project-requests/'.$a->id.'/revisions/'.$b->latest_revision_id)->assertNotFound();
        $this->browser('GET', '/api/v1/customers/'.Str::uuid7().'/project-requests/'.$a->id)->assertNotFound();
        $this->browser('GET', '/api/v1/project-requests/not-a-uuid')->assertNotFound();
        $this->browser('GET', '/api/v1/project-requests/'.$a->id.'/revisions/not-a-uuid')->assertNotFound();
    }

    #[DataProvider('staffRoles')]
    public function test_staff_permission_matrix_is_enforced_over_real_mfa_cookie_requests(string $role, bool $canRead, bool $canTaxonomy): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $staff = $this->intakeStaff($role);
        DB::table('project_requests')->where('id', $record->id)->update(['assigned_staff_id' => $staff->id]);
        $this->signInStaff($staff);
        $this->browser('GET', '/api/v1/admin/project-requests/'.$record->id)->assertStatus($canRead ? 200 : 403);
        $this->browser('GET', '/api/v1/admin/categories')->assertStatus($canTaxonomy ? 200 : 403);
        $this->browser('GET', '/api/v1/project-requests/'.$record->id)->assertForbidden();
        $this->browser('POST', '/api/v1/project-requests', [])->assertForbidden();
    }

    public static function staffRoles(): array
    {
        return [['super_admin', true, true], ['administrator', false, true], ['project_manager', true, false],
            ['business_analyst', true, false], ['reviewer', true, false], ['sales', false, false], ['support', false, false]];
    }

    public function test_staff_cannot_read_another_assignees_request_or_an_unsubmitted_draft(): void
    {
        $customer = $this->intakeCustomer();
        $record = $this->createSubmitted($customer);
        $draft = app(ManageDraft::class)->create($this->intakeActor($customer), [], (string) Str::uuid7());
        $staff = $this->intakeStaff();
        $other = $this->intakeStaff();
        DB::table('project_requests')->where('id', $record->id)->update(['assigned_staff_id' => $other->id]);
        $this->signInStaff($staff);
        $this->browser('GET', '/api/v1/admin/project-requests/'.$record->id)->assertNotFound();
        $this->browser('GET', '/api/v1/admin/project-requests/'.$draft->id)->assertNotFound();
        $this->browser('GET', '/api/v1/admin/project-requests?q=Learning')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_password_only_staff_and_customers_cannot_use_admin_intake(): void
    {
        $this->signIn($this->intakeStaff())->assertAccepted();
        $this->browser('GET', '/api/v1/admin/project-requests')->assertUnauthorized();
        $this->initializeBrowser();
        $this->signIn($this->intakeCustomer())->assertOk();
        $this->browser('GET', '/api/v1/admin/project-requests')->assertForbidden();
        $this->browser('POST', '/api/v1/admin/categories', ['name' => 'Forged', 'slug' => 'forged', 'active' => true, 'display_order' => 0])->assertForbidden();
    }

    public function test_assignment_requires_eligible_staff_and_explicit_precondition(): void
    {
        $customer = $this->intakeCustomer();
        $record = $this->createSubmitted($customer);
        $pm = $this->intakeStaff();
        $support = $this->intakeStaff('support');
        $disabled = $this->intakeStaff('reviewer');
        $disabled->enabled = false;
        $disabled->save();
        $this->signInStaff($pm);
        $path = '/api/v1/admin/project-requests/'.$record->id.'/assignments';
        $this->browser('POST', $path, ['assignee_id' => $pm->id])->assertStatus(428);
        $headers = ['If-Match' => VersionPrecondition::etag($record->id, $record->lock_version)];
        foreach ([$customer->id, $support->id, $disabled->id, (string) Str::uuid7()] as $invalid) {
            $this->browser('POST', $path, ['assignee_id' => $invalid], $headers)->assertUnprocessable();
        }
        $assigned = $this->browser('POST', $path, ['assignee_id' => $pm->id], $headers)->assertOk();
        $this->browser('POST', '/api/v1/admin/project-requests/'.$record->id.'/reviews', [], ['If-Match' => $assigned->headers->get('ETag')])->assertOk()->assertJsonPath('data.state', 'under_review');
        $this->browser('PATCH', '/api/v1/admin/project-requests/'.$record->id, ['state' => 'discovery'])->assertStatus(405);
    }

    public function test_cursor_pagination_filters_search_and_query_budget(): void
    {
        $customer = $this->intakeCustomer();
        for ($i = 0; $i < 4; $i++) {
            $this->createSubmitted($customer);
        }
        $this->signIn($customer)->assertOk();
        $first = $this->browser('GET', '/api/v1/project-requests?limit=2')->assertOk()->assertJsonCount(2, 'data');
        $cursor = $first->json('meta.next_cursor');
        self::assertIsString($cursor);
        $second = $this->browser('GET', '/api/v1/project-requests?limit=2&cursor='.rawurlencode($cursor))->assertOk()->assertJsonCount(2, 'data');
        self::assertSame([], array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
        self::assertNull($second->json('meta.next_cursor'));
        $this->browser('GET', '/api/v1/project-requests?q='.rawurlencode('تعليم'))->assertOk()->assertJsonCount(4, 'data');
        $this->browser('GET', '/api/v1/project-requests?q=Learning')->assertOk()->assertJsonCount(4, 'data');
        foreach (['limit=101', 'limit=0', 'sort=private_field', 'include=customer', 'q=a', 'cursor=bad', 'category_id=bad'] as $bad) {
            $this->browser('GET', '/api/v1/project-requests?'.$bad)->assertUnprocessable();
        }
        DB::enableQueryLog();
        $this->browser('GET', '/api/v1/project-requests?limit=1')->assertOk();
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->browser('GET', '/api/v1/project-requests?limit=100')->assertOk();
        self::assertLessThanOrEqual($one + 1, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_taxonomy_creation_edit_deactivation_and_stale_etags(): void
    {
        $staff = $this->intakeStaff('administrator');
        $this->signInStaff($staff);
        $created = $this->browser('POST', '/api/v1/admin/categories', ['name' => 'Web', 'slug' => 'web', 'active' => true, 'display_order' => 0])->assertCreated();
        $id = $created->json('data.id');
        $etag = $created->headers->get('ETag');
        $this->browser('PATCH', '/api/v1/admin/categories/'.$id, ['active' => false])->assertStatus(428);
        $this->browser('PATCH', '/api/v1/admin/categories/'.$id, ['active' => false], ['If-Match' => $etag])->assertOk();
        $this->browser('PATCH', '/api/v1/admin/categories/'.$id, ['active' => true], ['If-Match' => $etag])->assertStatus(412);
        $this->browser('GET', '/api/v1/categories')->assertOk()->assertJsonCount(0, 'data');
        $this->browser('GET', '/api/v1/admin/categories')->assertOk()->assertJsonCount(1, 'data');
        $this->browser('PATCH', '/api/v1/admin/categories/'.$id, ['slug' => 'replace'], ['If-Match' => $etag])->assertUnprocessable();
    }

    private function signInStaff(User $user): void
    {
        $this->signIn($user)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }

    public function test_authenticated_text_submission_remains_durable_without_redis(): void
    {
        $user = $this->intakeCustomer();
        $this->signIn($user)->assertOk();
        $input = $this->intakeInput();
        foreach (['default', 'cache'] as $connection) {
            Config::set('database.redis.'.$connection.'.host', '127.0.0.1');
            Config::set('database.redis.'.$connection.'.port', 1);
            app('redis')->purge($connection);
        }
        $draft = $this->browser('POST', '/api/v1/project-requests', $input)->assertCreated();
        $id = $draft->json('data.id');
        $this->browser('POST', '/api/v1/project-requests/'.$id.'/submissions', [],
            ['If-Match' => $draft->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->assertDatabaseCount('request_revisions', 1);
        $this->assertDatabaseCount('intake_notification_intents', 1);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'intake.submitted', 'subject_id' => $id]);
    }
}
