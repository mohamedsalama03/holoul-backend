<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use App\Application\Commercial\ExpireProposals;
use App\Infrastructure\Clock\DatabaseClock;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\AssignRequest;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Actions\TransitionRequest;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\CommercialDatabase;
use Tests\Support\CommercialFixtures;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

final class IntakeDisplayHttpTest extends TestCase
{
    use CommercialDatabase;
    use CommercialFixtures;
    use IdentityHttp;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.redis.options.prefix', 'intake_display_'.Str::uuid7().'_');
        app('redis')->purge('cache');
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        $this->initializeBrowser();
    }

    public function test_opt_in_links_to_directory_and_preserves_default_reads_and_command_responses(): void
    {
        $customer = $this->intakeCustomer();
        $record = $this->createSubmitted($customer);
        $customer->update(['full_name' => 'Current customer name']);
        $manager = $this->intakeStaff();
        $this->staffLogin($manager);
        $path = '/api/v1/admin/project-requests/'.$record->id;
        $legacy = $this->browser('GET', $path)->assertOk()->json('data');
        $display = $this->browser('GET', $path.'?view=dashboard')->assertOk()
            ->assertJsonPath('data.customer_id', $record->customer_id)
            ->assertJsonPath('data.customer_display_name', 'Current customer name')
            ->assertJsonPath('data.latest_revision.full_name', 'عميل Test')
            ->assertJsonPath('data.project_name', 'منصة تعليم Learning portal')
            ->assertJsonPath('data.provenance', 'customer')->assertJsonPath('data.claimed', false)
            ->assertJsonPath('data.assigned_staff', null);
        self::assertSame($legacy, array_diff_key($display->json('data'), array_flip($this->extraFields())));
        self::assertSame($display->json('data.etag'), $display->headers->get('ETag'));
        self::assertSame([], $display->headers->getCookies());
        $this->browser('GET', '/api/v1/admin/customers/'.$record->customer_id)->assertOk();
        $this->browser('GET', '/api/v1/admin/customers/'.$customer->id)->assertNotFound();
        $this->browser('GET', '/api/v1/admin/project-requests/by-reference/'.$record->reference.'?view=dashboard')->assertOk()
            ->assertJsonPath('data.customer_id', $record->customer_id);
        $summary = $this->browser('GET', '/api/v1/admin/project-requests?view=dashboard&reference='.$record->reference)->assertOk()->json('data.0');
        foreach ($this->extraFields() as $field) {
            self::assertSame($display->json('data.'.$field), $summary[$field]);
        }
        $legacySummary = $this->browser('GET', '/api/v1/admin/project-requests?reference='.$record->reference)->assertOk()->json('data.0');
        self::assertSame($legacySummary, array_diff_key($summary, array_flip($this->extraFields())));
        $assigned = $this->browser('POST', $path.'/assignments', ['assignee_id' => $manager->id], ['If-Match' => $legacy['etag']])->assertOk();
        self::assertSame(array_keys($legacySummary), array_keys($assigned->json('data')));
        self::assertSame($assigned->json('data.etag'), $assigned->headers->get('ETag'));
        $this->browser('POST', $path.'/reviews', [], ['If-Match' => $legacy['etag']])->assertStatus(412);
    }

    public function test_closed_request_keeps_current_assignee_and_disabled_historical_staff_names(): void
    {
        $record = $this->createSubmitted($this->intakeCustomer());
        $author = $this->intakeStaff('super_admin');
        $previous = $this->intakeStaff('reviewer');
        $current = $this->intakeStaff('reviewer');
        $author->update(['full_name' => 'Assigning person']);
        $previous->update(['full_name' => 'Previous reviewer']);
        $current->update(['full_name' => 'Current reviewer']);
        foreach ([$previous, $current] as $staff) {
            $record = app(AssignRequest::class)->handle($this->intakeActor($author), $record->id, $this->commercialEtag($record), $staff->id, (string) Str::uuid7());
        }
        $record = app(TransitionRequest::class)->handle($this->intakeActor($author), $record->id, $this->commercialEtag($record), 'review', null, (string) Str::uuid7());
        app(TransitionRequest::class)->handle($this->intakeActor($author), $record->id, $this->commercialEtag($record), 'reject', 'Closed for this test.', (string) Str::uuid7());
        $previous->update(['enabled' => false]);
        $author->update(['enabled' => false]);
        $this->staffLogin($current);
        $path = '/api/v1/admin/project-requests/'.$record->id;
        $this->browser('GET', '/api/v1/identity/staff')->assertForbidden();
        $this->browser('GET', $path.'?view=dashboard')->assertOk()
            ->assertJsonPath('data.assigned_staff', ['id' => $current->id, 'display_name' => 'Current reviewer'])
            ->assertJsonPath('data.state', 'rejected');
        $legacy = $this->browser('GET', $path.'/assignments')->assertOk()->json('data');
        $rows = $this->browser('GET', $path.'/assignments?view=dashboard')->assertOk()->json('data');
        self::assertSame(['id' => $previous->id, 'display_name' => 'Previous reviewer'], $rows[0]['assigned_staff']);
        self::assertNull($rows[0]['previous_staff']);
        self::assertSame($rows[0]['assigned_staff'], $rows[1]['previous_staff']);
        self::assertSame(['id' => $author->id, 'display_name' => 'Assigning person'], $rows[1]['assigned_by_staff']);
        foreach ($rows as $i => $row) {
            self::assertSame($legacy[$i], array_diff_key($row, array_flip(['previous_staff', 'assigned_staff', 'assigned_by_staff'])));
            self::assertSame(['id', 'display_name'], array_keys($row['assigned_staff']));
        }
        $page = $this->browser('GET', $path.'/assignments?view=dashboard&limit=1')->assertOk()->assertJsonCount(1, 'data');
        $this->browser('GET', $path.'/assignments?view=dashboard&after='.$page->json('meta.next_after'))->assertOk()
            ->assertJsonPath('data.0.id', $rows[1]['id']);
    }

    public function test_history_distinguishes_customer_and_staff_and_preserves_legacy_shape(): void
    {
        $customer = $this->intakeCustomer();
        $record = $this->createSubmitted($customer);
        $manager = $this->intakeStaff();
        $record = app(AssignRequest::class)->handle($this->intakeActor($manager), $record->id, $this->commercialEtag($record), $manager->id, (string) Str::uuid7());
        $record = app(TransitionRequest::class)->handle($this->intakeActor($manager), $record->id, $this->commercialEtag($record), 'review', null, (string) Str::uuid7());
        app(TransitionRequest::class)->handle($this->intakeActor($customer), $record->id, $this->commercialEtag($record), 'withdraw', null, (string) Str::uuid7());
        $customer->update(['enabled' => false]);
        $this->staffLogin($manager);
        $path = '/api/v1/admin/project-requests/'.$record->id.'/history';
        $legacy = $this->browser('GET', $path)->assertOk()->json('data');
        $view = $this->browser('GET', $path.'?view=dashboard')->assertOk();
        self::assertSame(['customer', 'staff', 'customer'], array_column(array_column($view->json('data'), 'actor'), 'kind'));
        foreach ($view->json('data') as $i => $row) {
            self::assertSame($legacy[$i], array_diff_key($row, ['actor' => true]));
            self::assertSame($row['actor_id'], $row['actor']['id']);
            self::assertSame(['id', 'kind', 'display_name'], array_keys($row['actor']));
        }
        $page = $this->browser('GET', $path.'?view=dashboard&limit=1')->assertOk();
        $this->browser('GET', $path.'?view=dashboard&after='.$page->json('meta.next_after'))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_guest_provenance_and_actor_survive_claim_and_a_later_customer_amendment(): void
    {
        $customer = $this->intakeCustomer();
        $created = $this->browser('POST', '/api/v1/guest/project-requests')->assertCreated();
        $id = $created->json('data.draft_id');
        $submitted = $this->browser('POST', '/api/v1/guest/project-requests/'.$id.'/submissions',
            [...$this->intakeInput(), 'full_name' => 'Original guest', 'email' => $customer->email, 'phone' => '+218912345678'],
            ['If-Match' => $created->headers->get('ETag'), 'X-Intake-Capability' => $created->json('data.capability'), 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $guestCookies = $this->browserCookies;
        $manager = $this->intakeStaff();
        $this->initializeBrowser();
        $this->staffLogin($manager);
        $staffCookies = $this->browserCookies;
        $path = '/api/v1/admin/project-requests/'.$id;
        $this->browser('GET', $path.'?view=dashboard')->assertOk()->assertJsonPath('data.customer_id', null)
            ->assertJsonPath('data.customer_display_name', 'Original guest')->assertJsonPath('data.provenance', 'guest')->assertJsonPath('data.claimed', false);
        $before = $this->browser('GET', $path.'/history?view=dashboard')->assertOk()->json('data');
        self::assertSame(['id' => null, 'kind' => 'guest', 'display_name' => 'Original guest'], $before[0]['actor']);
        $this->browserCookies = $guestCookies;
        $this->signIn($customer)->assertOk();
        $this->browser('POST', '/api/v1/project-request-claims', ['token' => $submitted->json('data.claim_token')], ['Idempotency-Key' => (string) Str::uuid7()])->assertOk();
        $record = ProjectRequest::query()->findOrFail($id);
        app(ManageDraft::class)->amend($this->intakeActor($customer), $id, $this->commercialEtag($record), (string) Str::uuid7());
        $record->refresh();
        app(ManageDraft::class)->update($this->intakeActor($customer), $id, $this->commercialEtag($record), ['project_name' => 'Amended customer project'], (string) Str::uuid7());
        $record->refresh();
        app(SubmitRequest::class)->handle($this->intakeActor($customer), $id, $this->commercialEtag($record), (string) Str::uuid7(), (string) Str::uuid7());
        $this->browserCookies = $staffCookies;
        $this->browser('GET', $path.'?view=dashboard')->assertOk()->assertJsonPath('data.customer_id', $record->customer_id)
            ->assertJsonPath('data.customer_display_name', $customer->full_name)->assertJsonPath('data.provenance', 'guest')
            ->assertJsonPath('data.claimed', true)->assertJsonPath('data.project_name', 'Amended customer project');
        self::assertSame($before, $this->browser('GET', $path.'/history?view=dashboard')->assertOk()->json('data'));
    }

    public function test_automated_expiry_has_system_actor_not_guest_or_staff(): void
    {
        $fixture = $this->commercialFixture();
        $proposal = $this->issueProposal($fixture, validSeconds: 2);
        while (DatabaseClock::now()->lessThanOrEqualTo($proposal->valid_until)) {
            usleep(20_000);
        }
        self::assertSame(1, app(ExpireProposals::class)->handle(1));
        $this->staffLogin($fixture['author']);
        $rows = $this->browser('GET', '/api/v1/admin/project-requests/'.$fixture['request']->id.'/history?view=dashboard')->assertOk()->json('data');
        self::assertSame(['id' => null, 'kind' => 'system', 'display_name' => null], $rows[array_key_last($rows)]['actor']);
    }

    public function test_opt_in_cannot_expand_visibility_or_bypass_mfa_and_never_reissues_a_session(): void
    {
        $customer = $this->intakeCustomer();
        $record = $this->createSubmitted($customer);
        $draft = app(ManageDraft::class)->create($this->intakeActor($customer), [], (string) Str::uuid7());
        $other = $this->intakeStaff('reviewer');
        DB::table('project_requests')->where('id', $record->id)->update(['assigned_staff_id' => $other->id]);
        $manager = $this->intakeStaff();
        $this->signIn($manager)->assertAccepted();
        $this->browser('GET', '/api/v1/admin/project-requests?view=dashboard')->assertUnauthorized();
        $this->initializeBrowser();
        $this->staffLogin($manager);
        foreach (['', '/history', '/assignments'] as $suffix) {
            foreach ([$record->id, $draft->id, (string) Str::uuid7()] as $id) {
                $response = $this->browser('GET', '/api/v1/admin/project-requests/'.$id.$suffix.'?view=dashboard')->assertNotFound();
                self::assertSame([], $response->headers->getCookies());
            }
        }
        $this->browser('GET', '/api/v1/admin/project-requests?view=dashboard')->assertOk()->assertJsonCount(0, 'data');
        $this->browser('GET', '/api/v1/identity/staff')->assertForbidden();
        $stale = $this->browserCookies;
        $this->browser('POST', '/api/v1/auth/logout')->assertOk();
        $this->browserCookies = $stale;
        $response = $this->browser('GET', '/api/v1/admin/project-requests?view=dashboard')->assertUnauthorized();
        self::assertSame([], $response->headers->getCookies());
        $this->initializeBrowser();
        $this->signIn($customer)->assertOk();
        $this->browser('GET', '/api/v1/admin/project-requests?view=dashboard')->assertForbidden();
        $this->browser('GET', '/api/v1/project-requests?view=dashboard')->assertUnprocessable();
        $this->browser('GET', '/api/v1/project-requests/'.$record->id)->assertOk()->assertJsonMissingPath('data.customer_id');
        $this->initializeBrowser();
        $this->staffLogin($this->intakeStaff('administrator'));
        $this->browser('GET', '/api/v1/admin/project-requests?view=dashboard')->assertForbidden();
    }

    public function test_projection_pagination_is_bounded_and_has_no_per_row_identity_or_revision_queries(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createSubmitted($this->intakeCustomer());
        }
        $this->staffLogin($this->intakeStaff());
        $first = $this->browser('GET', '/api/v1/admin/project-requests?view=dashboard&limit=2')->assertOk()->assertJsonCount(2, 'data');
        $second = $this->browser('GET', '/api/v1/admin/project-requests?view=dashboard&limit=2&cursor='.rawurlencode($first->json('meta.next_cursor')))->assertOk()->assertJsonCount(2, 'data');
        self::assertSame([], array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
        DB::enableQueryLog();
        $this->browser('GET', '/api/v1/admin/project-requests?view=dashboard&limit=1')->assertOk();
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $view = $this->browser('GET', '/api/v1/admin/project-requests?view=dashboard&limit=100')->assertOk()->assertJsonCount(5, 'data');
        self::assertLessThanOrEqual($one, count(DB::getQueryLog()));
        DB::disableQueryLog();
        foreach (['email', 'phone_e164', 'project_description', 'draft', 'password', 'capability', 'claim_token'] as $secret) {
            self::assertArrayNotHasKey($secret, $view->json('data.0'));
        }
        foreach (['view=all', 'view[]=dashboard', 'view=dashboard&staff_id='.Str::uuid7(), 'view=dashboard&limit=101'] as $query) {
            $this->browser('GET', '/api/v1/admin/project-requests?'.$query)->assertUnprocessable();
        }
        $id = $view->json('data.0.id');
        $this->browser('POST', '/api/v1/admin/project-requests/'.$id.'/reviews?view=dashboard')->assertUnprocessable();
    }

    private function extraFields(): array
    {
        return ['customer_id', 'project_name', 'customer_display_name', 'provenance', 'claimed', 'assigned_staff'];
    }

    private function staffLogin(User $staff): void
    {
        $this->signIn($staff)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }
}
