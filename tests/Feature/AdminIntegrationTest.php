<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Identity\Mfa\MfaCredential;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\AssignRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class AdminIntegrationTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBrowser();
    }

    public function test_customers_and_staff_without_business_scope_cannot_enumerate_customers(): void
    {
        $a = $this->intakeCustomer();
        $b = $this->intakeCustomer();
        $customer = DB::table('customers')->where('user_id', $b->id)->value('id');
        $this->signIn($a)->assertOk();
        foreach (['/api/v1/admin/customers', '/api/v1/admin/customers/'.$customer, '/api/v1/admin/customers/'.$customer.'/projects'] as $path) {
            $this->browser('GET', $path)->assertForbidden()->assertJsonMissing(['id' => $customer]);
        }
        foreach (['support', 'administrator'] as $role) {
            $this->initializeBrowser();
            $this->staffLogin($this->intakeStaff($role));
            $this->browser('GET', '/api/v1/admin/customers')->assertForbidden();
            $this->browser('GET', '/api/v1/admin/customers/'.$customer)->assertForbidden();
        }
    }

    public function test_customer_search_is_bounded_literal_and_has_only_safe_contact_fields(): void
    {
        $a = $this->intakeCustomer(false);
        $a->update(['full_name' => 'Alpha %_ Literal', 'email' => 'alpha.search@example.test']);
        $b = $this->intakeCustomer();
        $b->update(['full_name' => 'Beta Contact', 'enabled' => false]);
        $this->staffLogin($this->intakeStaff('super_admin'));
        $first = $this->browser('GET', '/api/v1/admin/customers?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertHeader('Cache-Control', 'no-store, private');
        $after = $first->json('meta.next_after');
        self::assertNotNull($after);
        $this->browser('GET', '/api/v1/admin/customers?limit=1&after='.$after)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_after', null);
        foreach (['%_', 'alpha.search', '+218912345678'] as $q) {
            $r = $this->browser('GET', '/api/v1/admin/customers?q='.rawurlencode($q))->assertOk();
            self::assertNotEmpty($r->json('data'));
            if ($q !== '+218912345678') {
                $r->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Alpha %_ Literal');
            }
        }
        $row = $this->browser('GET', '/api/v1/admin/customers?status=active&email_verified=false')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $keys = array_keys($row);
        sort($keys);
        $expected = ['id', 'full_name', 'email', 'email_display', 'phone_e164', 'phone_display', 'account_status', 'email_verified', 'customer_since', 'request_count', 'active_project_count', 'completed_project_count', 'on_hold_project_count'];
        sort($expected);
        self::assertSame($expected, $keys);
        self::assertSame(0, $row['request_count']);
        $this->browser('GET', '/api/v1/admin/customers?status=disabled')->assertOk()->assertJsonPath('data.0.full_name', 'Beta Contact');
        foreach (['limit=51', 'limit=0', 'limit=%2B10', 'limit=1.0', 'limit[]=1', 'after=bad', 'sort=email', 'q=a', 'status=deleted', 'email_verified=1', 'q[]=abc', 'customer_id='.Str::uuid7()] as $query) {
            $this->browser('GET', '/api/v1/admin/customers?'.$query)->assertUnprocessable();
        }
        $this->browser('GET', '/api/v1/admin/customers/not-a-uuid')->assertNotFound();
    }

    public function test_directory_and_summaries_follow_existing_assignment_and_membership_visibility(): void
    {
        $fixture = $this->projectFixture();
        $visibleCustomer = DB::table('customers')->where('user_id', $fixture['customer']->id)->value('id');
        $other = $this->createSubmitted($this->intakeCustomer());
        $reviewer = $this->intakeStaff('reviewer');
        $manager = $this->intakeActor($fixture['author']);
        $request = $fixture['request'];
        // Converted requests cannot be reassigned; create a separate assigned request for this customer.
        $assigned = $this->createSubmitted($fixture['customer']);
        app(AssignRequest::class)->handle($manager, $assigned->id, $this->commercialEtag($assigned), $reviewer->id, (string) Str::uuid7());
        $this->staffLogin($reviewer);
        $this->browser('GET', '/api/v1/admin/customers')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleCustomer)->assertJsonPath('data.0.request_count', 1)->assertJsonPath('data.0.active_project_count', 0);
        $this->browser('GET', '/api/v1/admin/customers/'.$other->customer_id)->assertNotFound();
        $this->browser('GET', '/api/v1/admin/customers/'.$visibleCustomer)->assertOk()->assertJsonPath('data.request_count', 1)
            ->assertJsonPath('data.links.projects', '/api/v1/admin/customers/'.$visibleCustomer.'/projects');
        $this->browser('GET', '/api/v1/admin/customers/'.$visibleCustomer.'/project-requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $assigned->id);
        $this->browser('GET', '/api/v1/admin/customers/'.$visibleCustomer.'/projects')->assertOk()->assertJsonCount(0, 'data');
        // Existing member grant opens only the matching Project, never all of this customer's work.
        $this->projectCommand($fixture['author'], $fixture['project'], 'project.team.add', input: ['staff_id' => $reviewer->id, 'role' => 'contributor']);
        $this->browser('GET', '/api/v1/admin/customers/'.$visibleCustomer)->assertOk()->assertJsonPath('data.active_project_count', 1)->assertJsonPath('data.request_count', 1);
        $this->browser('GET', '/api/v1/admin/customers/'.$visibleCustomer.'/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $fixture['project']->id);
        // Remove request visibility; Project membership alone must not reveal its source request.
        $assigned->refresh();
        app(AssignRequest::class)->handle($manager, $assigned->id, $this->commercialEtag($assigned), $fixture['author']->id, (string) Str::uuid7());
        $this->browser('GET', '/api/v1/admin/customers/'.$visibleCustomer.'/project-requests')->assertOk()->assertJsonCount(0, 'data');
        self::assertNotSame($request->id, $assigned->id);
    }

    public function test_customer_and_picker_query_counts_do_not_grow_with_page_size(): void
    {
        // Session garbage collection is probabilistic maintenance, not per-row business work.
        Config::set('session.lottery', [0, 100]);
        $fixture = $this->projectFixture();
        $customer = $this->intakeCustomer();
        $record = $this->createSubmitted($customer);
        for ($i = 0; $i < 6; $i++) {
            $this->intakeCustomer();
            $this->intakeStaff('reviewer');
        }
        $this->staffLogin($fixture['author']);
        foreach (['/api/v1/admin/customers', '/api/v1/admin/project-requests/'.$record->id.'/eligible-assignees',
            '/api/v1/admin/projects/'.$fixture['project']->id.'/eligible-staff?role=contributor'] as $path) {
            $counts = [];
            $totals = [];
            foreach ([1, 50] as $limit) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $response = $this->browser('GET', $path.(str_contains($path, '?') ? '&' : '?').'limit='.$limit)->assertOk();
                    self::assertGreaterThanOrEqual($limit === 1 ? 1 : 6, count($response->json('data')));
                    $totals[] = count(DB::getQueryLog());
                    // Last-activity writes vary at the timestamp boundary. All SELECTs,
                    // including authorization reads, must remain constant per page.
                    $counts[] = count(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'select ')));
                } finally {
                    DB::disableQueryLog();
                }
            }
            self::assertSame($counts[0], $counts[1], $path.' must not issue a SELECT per item');
            self::assertLessThan(35, max($totals));
            $capture = getenv('HOLOUL_CONTRACT_CAPTURE');
            if ($capture === '/verification-artifacts/contract-samples.jsonl') {
                file_put_contents('/verification-artifacts/p3-query-counts.jsonl', json_encode([
                    'path' => preg_replace('/[0-9a-f]{8}-[0-9a-f-]{27}/', '{id}', $path),
                    'limits' => [1, 50], 'select_counts' => $counts, 'total_queries' => $totals,
                ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            }
        }
    }

    public function test_intake_picker_requires_context_excludes_ineligible_staff_and_rechecks_stale_candidate(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $candidate = $this->intakeStaff('reviewer');
        $disabled = $this->intakeStaff('reviewer');
        $disabled->update(['enabled' => false]);
        $ineligible = $this->intakeStaff('support');
        $manager = $this->intakeStaff('project_manager');
        $this->staffLogin($manager);
        $path = '/api/v1/admin/project-requests/'.$record->id;
        $page = $this->browser('GET', $path.'/eligible-assignees?limit=50')->assertOk()->assertHeader('ETag', $this->commercialEtag($record));
        self::assertContains($candidate->id, array_column($page->json('data'), 'id'));
        self::assertNotContains($disabled->id, array_column($page->json('data'), 'id'));
        self::assertNotContains($ineligible->id, array_column($page->json('data'), 'id'));
        self::assertSame(['id', 'display_name', 'capability'], array_keys($page->json('data.0')));
        $this->browser('GET', $path.'/eligible-assignees?project_id='.Str::uuid7())->assertUnprocessable();
        $this->browser('GET', '/api/v1/admin/project-requests/'.Str::uuid7().'/eligible-assignees')->assertNotFound();
        $candidate->update(['enabled' => false]);
        $this->browser('POST', $path.'/assignments', ['assignee_id' => $candidate->id], ['If-Match' => $this->commercialEtag($record)])->assertUnprocessable();
        self::assertNull($record->refresh()->assigned_staff_id);
        $candidate->update(['enabled' => true]);
        DB::table('user_roles')->where('user_id', $candidate->id)->delete();
        $this->browser('POST', $path.'/assignments', ['assignee_id' => $candidate->id], ['If-Match' => $this->commercialEtag($record)])->assertUnprocessable();
        $valid = $this->intakeStaff('reviewer');
        $this->browser('POST', $path.'/assignments', ['assignee_id' => $valid->id], ['If-Match' => $this->commercialEtag($record)])->assertOk();
        // The PM loses visibility after assigning the request to another staff member.
        $this->browser('GET', $path.'/eligible-assignees')->assertNotFound();
        $this->initializeBrowser();
        $this->staffLogin($valid);
        $this->browser('GET', $path.'/eligible-assignees')->assertForbidden();
    }

    public function test_project_picker_matches_role_and_membership_rules_and_rejects_revoked_candidate(): void
    {
        $fixture = $this->projectFixture();
        $project = $fixture['project'];
        $ba = $this->intakeStaff('business_analyst');
        $pm = $this->intakeStaff('project_manager');
        $disabled = $this->intakeStaff('project_manager');
        $disabled->update(['enabled' => false]);
        $this->staffLogin($fixture['author']);
        $path = '/api/v1/admin/projects/'.$project->id;
        $eligible = $this->browser('GET', $path.'/eligible-staff?role=project_manager')->assertOk()->json('data');
        self::assertContains($pm->id, array_column($eligible, 'id'));
        foreach ([$ba, $disabled, $fixture['author']] as $excluded) {
            self::assertNotContains($excluded->id, array_column($eligible, 'id'));
        }
        $this->browser('GET', $path.'/eligible-staff?role=business_analyst&q=Intake')->assertOk()->assertJsonPath('context.role', 'business_analyst');
        $this->browser('GET', $path.'/eligible-staff')->assertUnprocessable();
        $this->browser('GET', $path.'/eligible-staff?role=super_admin')->assertUnprocessable();
        DB::table('user_roles')->where('user_id', $pm->id)->delete();
        $this->browser('POST', $path.'/team-members', ['staff_id' => $pm->id, 'role' => 'project_manager'],
            ['If-Match' => VersionPrecondition::etag($project->id, $project->lock_version), 'Idempotency-Key' => (string) Str::uuid7()])->assertUnprocessable();
        $this->assertDatabaseMissing('project_members', ['project_id' => $project->id, 'user_id' => $pm->id]);
        $this->browser('POST', $path.'/team-members', ['staff_id' => $ba->id, 'role' => 'business_analyst'],
            ['If-Match' => VersionPrecondition::etag($project->id, $project->lock_version), 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->initializeBrowser();
        $this->staffLogin($ba);
        $this->browser('GET', $path.'/eligible-staff?role=contributor')->assertForbidden();
        $this->browser('GET', '/api/v1/admin/projects/'.Str::uuid7().'/eligible-staff?role=contributor')->assertNotFound();
    }

    public function test_current_capabilities_are_session_bound_cannot_be_forged_and_follow_live_permission_revocation(): void
    {
        $staff = $this->intakeStaff('project_manager');
        $this->signIn($staff)->assertAccepted();
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        $this->initializeBrowser();
        $this->staffLogin($staff);
        $response = $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.kind', 'staff')->assertJsonPath('data.roles', ['project_manager'])
            ->assertJsonPath('data.mfa_required', true)->assertJsonPath('data.mfa_satisfied', true)
            ->assertJsonPath('data.recent_password_confirmation.required', false);
        self::assertContains('admin.customers.view', $response->json('data.capabilities'));
        self::assertContains('project_requests.assign', $response->json('data.capabilities'));
        self::assertNotContains('ai.request', $response->json('data.capabilities'));
        DB::table('role_permissions')->where('role_id', DB::table('roles')->where('code', 'project_manager')->value('id'))
            ->where('permission_id', DB::table('permissions')->where('code', 'customers.directory.read')->value('id'))->delete();
        $next = $this->browser('GET', '/api/v1/identity/me')->assertOk();
        self::assertNotContains('admin.customers.view', $next->json('data.capabilities'));
        $this->browser('GET', '/api/v1/admin/customers', headers: ['X-Capabilities' => 'admin.customers.view'])->assertForbidden();
        $this->browser('PATCH', '/api/v1/identity/me', ['full_name' => 'Changed', 'capabilities' => ['admin.customers.view']])->assertUnprocessable();
        $this->travel(Config::integer('identity.recent_password_seconds') + 1)->seconds();
        $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.recent_password_confirmation.required', true);
        $this->travelBack();
    }

    public function test_authorized_role_change_revokes_old_capabilities_and_new_session_has_new_role(): void
    {
        $target = $this->intakeStaff('reviewer');
        $this->staffLogin($target);
        $targetCookies = $this->browserCookies;
        $this->initializeBrowser();
        $this->staffLogin($this->intakeStaff('super_admin'));
        $this->browser('PATCH', '/api/v1/identity/staff/'.$target->id.'/authorization', ['roles' => ['support'], 'enabled' => true])->assertOk();
        $this->browserCookies = $targetCookies;
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        $this->initializeBrowser();
        $this->signIn($target)->assertAccepted();
        $secret = MfaCredential::query()->where('user_id', $target->id)->sole()->secret;
        $this->travel(31)->seconds();
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['code' => (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30))])->assertOk();
        $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.roles', ['support']);
        $this->browser('GET', '/api/v1/admin/customers')->assertForbidden();
        $this->travelBack();
    }

    public function test_customer_capabilities_follow_verification_without_granting_staff_access(): void
    {
        $customer = $this->intakeCustomer(false);
        $this->signIn($customer)->assertOk();
        $view = $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.roles', ['customer'])
            ->assertJsonPath('data.mfa_required', false)->assertJsonPath('data.mfa_satisfied', true);
        self::assertNotContains('project_requests.submit', $view->json('data.capabilities'));
        self::assertContains('project_requests.create', $view->json('data.capabilities'));
        $customer->update(['email_verified_at' => now()]);
        $view = $this->browser('GET', '/api/v1/identity/me')->assertOk();
        self::assertContains('project_requests.submit', $view->json('data.capabilities'));
        self::assertNotContains('admin.customers.view', $view->json('data.capabilities'));
        foreach (['/api/v1/admin/project-requests/'.Str::uuid7().'/eligible-assignees', '/api/v1/admin/projects/'.Str::uuid7().'/eligible-staff?role=contributor'] as $path) {
            $this->browser('GET', $path)->assertForbidden();
        }
    }

    public function test_capability_read_serializes_with_real_concurrent_session_revocation(): void
    {
        $staff = $this->intakeStaff('reviewer');
        $this->staffLogin($staff);
        $previousVersion = $staff->refresh()->auth_version;
        $application = 'p3-capability-revoke-'.Str::uuid7();
        $peer = null;
        $reached = false;
        $program = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            if (!$app->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'holoul_test') { throw new LogicException('Test database required.'); }
            Illuminate\Support\Facades\DB::select('SELECT set_config(?, ?, false)', ['application_name', $argv[2]]);
            Illuminate\Support\Facades\DB::statement("SET lock_timeout='10s'");
            $version = $app->make(App\Modules\Identity\Security\SessionSecurity::class)->revokeAll($argv[1], (string) Illuminate\Support\Str::uuid7());
            echo json_encode(['version'=>$version], JSON_THROW_ON_ERROR);
            PHP;
        DB::listen(function (QueryExecuted $query) use (&$peer, &$reached, $staff, $application, $program): void {
            if ($reached || ! str_contains($query->sql, 'from "users"') || ! str_contains($query->sql, 'for no key update')) {
                return;
            }
            $reached = true;
            $peer = new Process([PHP_BINARY, '-r', $program, '--', $staff->id, $application], base_path(), timeout: 20);
            $peer->start();
            $deadline = microtime(true) + 8;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', $application)->where('wait_event_type', 'Lock')->count();
                if ($waiting === 1) {
                    break;
                }
                self::assertTrue($peer->isRunning(), 'Revocation must wait for the capability read transaction.');
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(1, $waiting);
        });
        try {
            $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.roles', ['reviewer']);
            self::assertTrue($reached);
            self::assertInstanceOf(Process::class, $peer);
            self::assertSame(0, $peer->wait(), $peer->getErrorOutput());
            self::assertSame($previousVersion + 1, json_decode($peer->getOutput(), true, flags: JSON_THROW_ON_ERROR)['version']);
            $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
            $this->browser('GET', '/api/v1/admin/customers')->assertUnauthorized();
        } finally {
            if ($peer instanceof Process && $peer->isRunning()) {
                $peer->stop(0);
            }
        }
    }

    public function test_terminal_context_and_existing_members_are_not_assignable(): void
    {
        $fixture = $this->projectFixture();
        $this->staffLogin($fixture['author']);
        $this->browser('GET', '/api/v1/admin/project-requests/'.$fixture['request']->id.'/eligible-assignees')->assertConflict();
        $project = $fixture['project'];
        $this->projectCommand($fixture['author'], $project, 'project.cancel', input: ['reason' => 'Scope closed.', 'customer_communication' => 'Project cancelled.']);
        $this->browser('GET', '/api/v1/admin/projects/'.$project->id.'/eligible-staff?role=contributor')->assertConflict();
    }

    private function staffLogin(User $staff): void
    {
        $this->signIn($staff)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }
}
