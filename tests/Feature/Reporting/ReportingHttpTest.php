<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\Reporting\Actions\OperationalReports;
use App\Modules\Reporting\Data\ReportWindow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class ReportingHttpTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->initializeBrowser();
    }

    #[DataProvider('staffGrants')]
    public function test_explicit_staff_grants_gate_reports_and_audit_with_real_mfa(string $role, bool $report, bool $audit): void
    {
        $staff = $this->intakeStaff($role);
        $this->staffLogin($staff);
        foreach (['dashboard', 'requests', 'projects', 'customers'] as $route) {
            $this->browser('GET', '/api/v1/admin/reports/'.$route)->assertStatus($report ? 200 : 403);
        }
        $this->browser('GET', '/api/v1/admin/audit-events')->assertStatus($audit ? 200 : 403);
    }

    public static function staffGrants(): array
    {
        return [['super_admin', true, true], ['administrator', true, false], ['project_manager', true, false],
            ['business_analyst', false, false], ['sales', false, false], ['reviewer', false, false], ['support', false, false]];
    }

    public function test_customer_forged_grants_unverified_staff_and_revocation_cannot_bypass_admin_access(): void
    {
        $this->browser('GET', '/api/v1/admin/reports/dashboard')->assertUnauthorized();
        $customer = $this->intakeCustomer();
        $customerRole = DB::table('roles')->where('code', 'customer')->value('id');
        foreach (['reporting.read', 'audit.investigate'] as $permission) {
            DB::table('role_permissions')->insert(['role_id' => $customerRole,
                'permission_id' => DB::table('permissions')->where('code', $permission)->value('id')]);
        }
        $this->signIn($customer)->assertOk();
        $this->browser('GET', '/api/v1/admin/reports/dashboard')->assertForbidden();
        $this->browser('GET', '/api/v1/admin/audit-events')->assertForbidden();
        $this->initializeBrowser();
        $staff = $this->intakeStaff('super_admin');
        $this->staffLogin($staff);
        $staff->forceFill(['email_verified_at' => null])->save();
        $this->browser('GET', '/api/v1/admin/reports/dashboard')->assertForbidden();
        $staff->forceFill(['email_verified_at' => now()])->save();
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->select('id')->whereIn('code', ['reporting.read', 'audit.investigate']))->delete();
        $this->browser('GET', '/api/v1/admin/reports/dashboard')->assertForbidden();
        $this->browser('GET', '/api/v1/admin/audit-events')->assertForbidden();
    }

    public function test_request_cohorts_exclude_drafts_keep_currencies_exact_and_filter_latest_submitted_taxonomy(): void
    {
        $customer = $this->intakeCustomer();
        $taxonomy = $this->intakeTaxonomy();
        $this->request($customer, [...$taxonomy, 'estimated_budget' => '12.34', 'currency' => 'USD']);
        $this->request($customer, [...$taxonomy, 'estimated_budget' => '56.789', 'currency' => 'LYD']);
        $this->request($customer, ['budget_unknown' => true, 'estimated_budget' => null, 'currency' => null]);
        app(ManageDraft::class)->create($this->intakeActor($customer), $this->intakeInput(), (string) Str::uuid7());
        $this->staffLogin($this->intakeStaff('administrator'));
        $view = $this->browser('GET', '/api/v1/admin/reports/requests')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $view->assertJsonPath('data.requests.counts.total_requests', 3)->assertJsonPath('data.requests.counts.new_requests', 3)
            ->assertJsonPath('data.requests.counts.unknown_budget_requests', 1)->assertJsonPath('data.requests.counts.conversion_rate_percent', '0.00')
            ->assertJsonPath('data.requests.review.reviewed_requests', 0)->assertJsonPath('data.requests.review.average_review_seconds', null)
            ->assertJsonPath('data.requests.estimated_budgets.0.currency', 'LYD')->assertJsonPath('data.requests.estimated_budgets.0.minor_units', '56789')
            ->assertJsonPath('data.requests.estimated_budgets.1.currency', 'USD')->assertJsonPath('data.requests.estimated_budgets.1.minor_units', '1234');
        foreach ([$customer->email, 'phone_e164', 'full_name', 'project_description', 'source_text', 'storage_key', 'Private description'] as $private) {
            self::assertStringNotContainsString($private, $view->getContent());
        }
        $this->browser('GET', '/api/v1/admin/reports/requests', ['category_id' => $taxonomy['category_id']])->assertOk()->assertJsonPath('data.requests.counts.total_requests', 2);
        $this->browser('GET', '/api/v1/admin/reports/requests', ['category_id' => $taxonomy['category_id'], 'subcategory_id' => (string) Str::uuid7()])
            ->assertOk()->assertJsonPath('data.requests.counts.total_requests', 0)->assertJsonPath('data.requests.counts.conversion_rate_percent', null);
        $old = ['from' => now('UTC')->subDays(3)->format('Y-m-d'), 'to' => now('UTC')->subDays(2)->format('Y-m-d')];
        $this->browser('GET', '/api/v1/admin/reports/requests', $old)->assertOk()->assertJsonPath('data.requests.counts.total_requests', 0);
    }

    public function test_dashboard_conversion_pending_proposals_review_denominators_and_project_pipeline_are_distinct(): void
    {
        $converted = $this->projectFixture();
        $pending = $this->commercialFixture();
        $this->issueProposal($pending, 'USD');
        $this->createSubmitted($this->intakeCustomer());
        $this->staffLogin($converted['author']);
        $response = $this->browser('GET', '/api/v1/admin/reports/dashboard')->assertOk();
        $response->assertJsonPath('data.requests.counts.total_requests', 3)->assertJsonPath('data.requests.counts.converted_requests', 1)
            ->assertJsonPath('data.requests.counts.conversion_rate_percent', '33.33')->assertJsonPath('data.requests.review.reviewed_requests', 2)
            ->assertJsonPath('data.proposals.counts.pending_proposals', 1)->assertJsonPath('data.proposals.counts.accepted_proposals', 1)
            ->assertJsonPath('data.proposals.timing.requests_with_issued_proposal', 2)
            ->assertJsonPath('data.proposals.accepted_proposal_values.0.currency', 'LYD')->assertJsonPath('data.proposals.accepted_proposal_values.0.minor_units', '30369')
            ->assertJsonPath('data.projects.counts.active_projects', 1)->assertJsonPath('data.projects.counts.completed_projects', 0)
            ->assertJsonPath('data.projects.pipeline.0.state', 'planning')->assertJsonPath('data.customers.total_customers', 3);
        self::assertGreaterThanOrEqual(0, (float) $response->json('data.requests.review.average_review_seconds'));
        self::assertGreaterThanOrEqual(0, (float) $response->json('data.proposals.timing.average_time_to_proposal_seconds'));
        self::assertStringNotContainsString('Private issued commercial scope.', $response->getContent());
        self::assertStringContainsString('never revenue', $response->getContent());
    }

    public function test_project_completion_and_hold_metrics_follow_authorized_lifecycle_history(): void
    {
        $f = $this->projectFixture();
        foreach (['plan_approved', 'design_approved', 'delivery_candidate', 'qa_passed', 'deployment_succeeded'] as $kind) {
            $this->projectCommand($f['author'], $f['project'], 'project.evidence', input: ['kind' => $kind, 'summary' => 'Verified phase evidence.']);
            if ($kind === 'deployment_succeeded') {
                $this->projectCommand($f['customer'], $f['project'], 'project.confirm');
            }
            $this->projectCommand($f['author'], $f['project'], 'project.advance');
        }
        $held = $this->projectFixture();
        $this->projectCommand($held['author'], $held['project'], 'project.hold', input: ['reason' => 'Dependency', 'customer_communication' => 'Waiting for approval.']);
        $this->staffLogin($f['author']);
        $view = $this->browser('GET', '/api/v1/admin/reports/projects')->assertOk();
        $view->assertJsonPath('data.projects.counts.total_projects', 2)->assertJsonPath('data.projects.counts.active_projects', 0)
            ->assertJsonPath('data.projects.counts.on_hold_projects', 1)->assertJsonPath('data.projects.counts.completed_projects', 1)
            ->assertJsonPath('data.projects.counts.completion_rate_percent', '50.00')->assertJsonPath('data.projects.completion.completed_in_period', 1);
        self::assertGreaterThanOrEqual(0, (float) $view->json('data.projects.completion.average_completion_seconds'));
    }

    public function test_queries_are_bounded_and_do_not_grow_per_record(): void
    {
        $this->createSubmitted($this->intakeCustomer());
        DB::enableQueryLog();
        $result = app(OperationalReports::class)->read('dashboard', ReportWindow::fromInput([]));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertCount(4, $result['data']);
        self::assertLessThanOrEqual(15, count($queries));
        foreach ($queries as $query) {
            self::assertStringNotContainsString('select *', strtolower($query['query']));
        }
    }

    public function test_report_is_one_snapshot_when_an_independent_submission_commits_between_aggregate_queries(): void
    {
        $customer = $this->intakeCustomer();
        $request = $this->createSubmitted($customer);
        $draft = $this->intakeInput();
        $this->staffLogin($this->intakeStaff('administrator'));
        $input = ['actor_id' => $customer->id, 'customer_id' => $request->customer_id, 'draft' => $draft];
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/reporting-concurrent-submit.php'), base64_encode(json_encode($input, JSON_THROW_ON_ERROR))],
            base_path(), ['APP_ENV' => 'testing'], timeout: 15);
        $submitted = false;
        $backend = DB::scalar('SELECT pg_backend_pid()');
        DB::listen(function (QueryExecuted $event) use (&$submitted, $process, $backend): void {
            if (! $submitted && str_contains($event->sql, 'AS total_requests')) {
                $submitted = true;
                self::assertSame(0, $process->run(), $process->getErrorOutput().$process->getOutput());
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertNotSame($backend, $result['backend']);
            }
        });
        $view = $this->browser('GET', '/api/v1/admin/reports/dashboard')->assertOk();
        self::assertTrue($submitted);
        $view->assertJsonPath('data.requests.counts.total_requests', 1)->assertJsonPath('data.requests.status_distribution.0.requests', 1)
            ->assertJsonPath('data.requests.trends.0.requests', 1)->assertJsonPath('data.requests.by_category.other_requests', 0);
        self::assertSame(2, DB::table('project_requests')->whereNotNull('submitted_at')->count());
    }

    #[DataProvider('unsafeFilters')]
    public function test_unknown_unbounded_and_injection_filters_fail_safely(array $filters, string $path): void
    {
        $this->staffLogin($this->intakeStaff('super_admin'));
        $response = $this->browser('GET', $path, $filters)->assertUnprocessable();
        foreach (['SQLSTATE', 'select *', 'stack', 'exception', 'password'] as $private) {
            self::assertStringNotContainsString($private, $response->getContent());
        }
    }

    public static function unsafeFilters(): array
    {
        return [[['sql' => 'select * from users'], '/api/v1/admin/reports/dashboard'],
            [['from' => '2025-01-01', 'to' => '2026-09-21'], '/api/v1/admin/reports/dashboard'],
            [['from' => '2026-02-30', 'to' => '2026-03-01'], '/api/v1/admin/reports/dashboard'],
            [['from' => '0000-01-01', 'to' => '0000-01-02'], '/api/v1/admin/reports/dashboard'],
            [['from' => '2026-09-21', 'to' => '2026-09-01'], '/api/v1/admin/reports/requests'],
            [['from' => '2026-09-01'], '/api/v1/admin/reports/requests'],
            [['subcategory_id' => '01950000-0000-7000-8000-000000000001'], '/api/v1/admin/reports/requests'],
            [['event_type' => "x' OR 1=1 --"], '/api/v1/admin/audit-events'],
            [['limit' => 101], '/api/v1/admin/audit-events'], [['cursor' => 'tampered'], '/api/v1/admin/audit-events']];
    }

    private function request(User $customer, array $patch): ProjectRequest
    {
        $actor = $this->intakeActor($customer);
        $request = app(ManageDraft::class)->create($actor, [...$this->intakeInput(), ...$patch], (string) Str::uuid7());
        app(SubmitRequest::class)->handle($actor, $request->id, $this->commercialEtag($request), (string) Str::uuid7(), (string) Str::uuid7());

        return $request->refresh();
    }

    private function staffLogin(User $staff): void
    {
        $this->signIn($staff)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }
}
