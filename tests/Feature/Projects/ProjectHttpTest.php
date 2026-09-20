<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\AssignRequest;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class ProjectHttpTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBrowser();
    }

    public function test_conversion_is_atomic_replays_exactly_and_retains_the_accepted_commercial_baseline(): void
    {
        $f = $this->commercialFixture();
        $proposal = $this->issueProposal($f);
        $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $proposal->id);
        $before = DB::table('proposals')->where('id', $proposal->id)->first();
        $decision = DB::table('proposal_decisions')->where('proposal_id', $proposal->id)->first();
        $this->staffLogin($f['author']);
        $path = '/api/v1/admin/project-requests/'.$f['request']->id.'/conversions';
        $headers = ['If-Match' => $this->commercialEtag($f['request']->refresh()), 'Idempotency-Key' => (string) Str::uuid7()];
        $this->browser('POST', $path, [], $headers, false)->assertForbidden();
        $this->browser('POST', $path, [], [...$headers, 'Origin' => 'https://other.test'])->assertForbidden();
        $this->browser('POST', $path, [], ['Idempotency-Key' => $headers['Idempotency-Key']])->assertStatus(428);
        $this->browser('POST', $path, [], ['If-Match' => $headers['If-Match']])->assertUnprocessable();
        $this->browser('POST', $path, ['customer_id' => (string) Str::uuid7()], $headers)->assertUnprocessable();
        $first = $this->browser('POST', $path, [], $headers)->assertCreated()->assertJsonPath('data.state', 'planning');
        $repeat = $this->browser('POST', $path, [], $headers)->assertCreated();
        self::assertSame($first->json(), $repeat->json());
        self::assertSame($first->headers->get('ETag'), $repeat->headers->get('ETag'));
        $project = Project::query()->findOrFail($first->json('data.project_id'));
        $first->assertHeader('Location', '/api/v1/admin/projects/'.$project->id);
        self::assertMatchesRegularExpression('/\APRJ-[0-9]{4}-[0-9]{5,}\z/', $project->reference);
        self::assertTrue(Str::isUuid($project->id, 7));
        self::assertSame($f['request']->id, $project->source_request_id);
        self::assertSame($proposal->id, $project->accepted_proposal_id);
        self::assertSame($decision->id, $project->accepted_decision_id);
        self::assertSame($decision->proposal_version, $project->accepted_proposal_version);
        $this->assertDatabaseHas('project_requests', ['id' => $f['request']->id, 'state' => 'converted']);
        $this->assertDatabaseHas('request_state_changes', ['request_id' => $f['request']->id, 'to_state' => 'converted']);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_command_keys', 1);
        $view = $this->browser('GET', '/api/v1/admin/projects/'.$project->id)->assertOk();
        $view->assertJsonPath('data.accepted_baseline.amount', '30.369')->assertJsonPath('data.accepted_baseline.currency', 'LYD')
            ->assertJsonPath('data.accepted_baseline.scope_summary', 'Private issued commercial scope.')
            ->assertJsonPath('data.accepted_baseline.items.0.unit_price', '10.123')
            ->assertJsonPath('data.accepted_baseline.deliverables', ['Deployed portal', 'Handover documentation']);
        self::assertEquals($before, DB::table('proposals')->where('id', $proposal->id)->first());
        $this->browser('POST', $path, [], [...$headers, 'Idempotency-Key' => (string) Str::uuid7()])->assertStatus(412);
        $this->browser('POST', $path, [], [...$headers, 'If-Match' => $this->commercialEtag($f['request']->refresh())])->assertConflict();
    }

    public function test_customer_cannot_convert_and_unaccepted_or_rescinded_requests_cannot_convert(): void
    {
        $f = $this->commercialFixture();
        $proposal = $this->issueProposal($f);
        $path = '/api/v1/admin/project-requests/'.$f['request']->id.'/conversions';
        $headers = fn (): array => ['If-Match' => $this->commercialEtag($f['request']->refresh()), 'Idempotency-Key' => (string) Str::uuid7()];
        $this->signIn($f['customer'])->assertOk();
        $this->browser('POST', $path, [], $headers())->assertForbidden();
        $this->browser('POST', '/api/v1/project-requests/'.$f['request']->id.'/conversions', [], $headers())->assertNotFound();
        $this->initializeBrowser();
        $this->staffLogin($f['author']);
        $this->browser('POST', $path, [], $headers())->assertConflict();
        $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $proposal->id);
        $this->commercialCommand($f['customer'], $f['request'], 'proposal.rescind', $proposal->id, ['reason' => 'Changed requirements.']);
        $this->browser('POST', $path, [], $headers())->assertConflict();
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_customer_visibility_isolation_and_forged_project_ownership_fail_closed(): void
    {
        $a = $this->projectFixture();
        $b = $this->projectFixture();
        $visible = $this->milestoneInput(true);
        $this->projectCommand($a['author'], $a['project'], 'project.milestone.create', input: $visible);
        $this->projectCommand($a['author'], $a['project'], 'project.milestone.create', input: [...$visible, 'name' => 'Staff-only milestone', 'display_order' => 2, 'customer_visible' => false]);
        $this->projectCommand($a['author'], $a['project'], 'project.update.publish', input: ['content' => 'Delivery is progressing.']);
        $this->signIn($a['customer'])->assertOk();
        $base = '/api/v1/projects/'.$a['project']->id;
        $this->browser('GET', '/api/v1/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a['project']->id);
        $this->browser('GET', $base)->assertOk()->assertJsonMissingPath('data.customer_user_id')->assertJsonMissingPath('data.phase_epoch');
        $this->browser('GET', $base.'/milestones')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.responsible_member_id');
        $this->browser('GET', $base.'/updates')->assertOk()->assertJsonPath('data.0.content', 'Delivery is progressing.');
        foreach (['', '/milestones', '/updates', '/documents'] as $suffix) {
            $this->browser('GET', '/api/v1/projects/'.$b['project']->id.$suffix)->assertNotFound();
        }
        $this->browser('POST', '/api/v1/projects/'.$b['project']->id.'/completion-confirmations', [], $this->headers($b['project']))->assertNotFound();
        foreach (['activity', 'team-members', 'evidence'] as $suffix) {
            $this->browser('GET', $base.'/'.$suffix)->assertNotFound();
            $this->browser('GET', '/api/v1/admin/projects/'.$a['project']->id.'/'.$suffix)->assertForbidden();
        }
        $this->browser('POST', '/api/v1/admin/projects/'.$a['project']->id.'/advances', [], $this->headers($a['project']))->assertForbidden();
        $this->browser('PATCH', $base, ['state' => 'completed'])->assertStatus(405);
        $this->browser('GET', '/api/v1/projects/not-a-uuid')->assertNotFound()->assertJsonMissingPath('exception');
        $this->browser('GET', '/api/v1/projects?state=forged')->assertUnprocessable();
    }

    #[DataProvider('staffMatrix')]
    public function test_staff_permissions_and_current_project_membership(string $role, bool $read, bool $manage, bool $manager): void
    {
        $f = $this->projectFixture();
        $staff = $this->intakeStaff($role);
        if ($read && $role !== 'super_admin') {
            $this->projectCommand($f['author'], $f['project'], 'project.team.add', input: ['staff_id' => $staff->id, 'role' => $manager ? 'project_manager' : 'contributor']);
        }
        $this->staffLogin($staff);
        $base = '/api/v1/admin/projects/'.$f['project']->id;
        $this->browser('GET', $base)->assertStatus($read ? 200 : 403);
        $this->browser('POST', $base.'/updates', ['content' => 'Customer-safe update'], $this->headers($f['project']))->assertStatus($manage ? 201 : 403);
        // An unassigned Super Admin cannot satisfy the assigned-PM lifecycle guard.
        $this->browser('POST', $base.'/advances', [], $this->headers($f['project']))->assertStatus($manager ? 409 : 403);
        if ($read && $role !== 'super_admin') {
            $member = DB::table('project_members')->where('project_id', $f['project']->id)->where('user_id', $staff->id)->value('id');
            $this->projectCommand($f['author'], $f['project'], 'project.team.remove', $member);
            $this->browser('GET', $base)->assertNotFound();
            $this->browser('POST', $base.'/updates', ['content' => 'No longer authorized'], $this->headers($f['project']))->assertNotFound();
        }
    }

    public static function staffMatrix(): array
    {
        return [['super_admin', true, true, false], ['project_manager', true, true, true], ['business_analyst', true, true, false],
            ['sales', true, false, false], ['reviewer', true, false, false], ['administrator', false, false, false], ['support', false, false, false]];
    }

    public function test_conversion_permission_is_independent_of_read_scope_and_assignment(): void
    {
        $f = $this->commercialFixture();
        $proposal = $this->issueProposal($f);
        $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $proposal->id);
        $sales = $this->intakeStaff('sales');
        app(AssignRequest::class)->handle($this->intakeActor($f['author']), $f['request']->id, $this->commercialEtag($f['request']->refresh()), $sales->id, (string) Str::uuid7());
        $this->staffLogin($sales);
        $this->browser('POST', '/api/v1/admin/project-requests/'.$f['request']->id.'/conversions', [],
            ['If-Match' => $this->commercialEtag($f['request']->refresh()), 'Idempotency-Key' => (string) Str::uuid7()])->assertForbidden();
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_command_replay_checks_current_permission_stale_versions_and_changed_payload(): void
    {
        $f = $this->projectFixture();
        $this->staffLogin($f['author']);
        $path = '/api/v1/admin/projects/'.$f['project']->id.'/updates';
        $headers = $this->headers($f['project']);
        $first = $this->browser('POST', $path, ['content' => 'Immutable update'], $headers)->assertCreated();
        self::assertSame($first->json(), $this->browser('POST', $path, ['content' => 'Immutable update'], $headers)->assertCreated()->json());
        $this->browser('POST', $path, ['content' => 'Altered'], $headers)->assertConflict();
        $this->browser('POST', $path, ['content' => 'Another'], [...$headers, 'Idempotency-Key' => (string) Str::uuid7()])->assertStatus(412);
        DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('code', 'projects.updates.publish')->value('id'))->delete();
        $this->browser('POST', $path, ['content' => 'Immutable update'], $headers)->assertForbidden();
        $this->assertDatabaseCount('project_updates', 1);
        DB::table('identity_sessions')->where('user_id', $f['author']->id)->delete();
        $this->browser('GET', '/api/v1/admin/projects/'.$f['project']->id)->assertUnauthorized();
    }

    public function test_customer_confirmation_requires_recent_authentication_and_cannot_be_replayed_after_revocation(): void
    {
        $f = $this->projectFixture();
        foreach (['plan_approved', 'design_approved', 'delivery_candidate', 'qa_passed'] as $kind) {
            $this->projectCommand($f['author'], $f['project'], 'project.evidence', input: ['kind' => $kind, 'summary' => 'Recorded approval and verification.']);
            $this->projectCommand($f['author'], $f['project'], 'project.advance');
        }
        $this->projectCommand($f['author'], $f['project'], 'project.evidence', input: ['kind' => 'deployment_succeeded', 'summary' => 'Release verified.']);
        $this->signIn($f['customer'])->assertOk();
        $path = '/api/v1/projects/'.$f['project']->id.'/completion-confirmations';
        $headers = $this->headers($f['project']);
        config(['identity.recent_password_seconds' => 0]);
        $this->browser('POST', $path, [], $headers)->assertForbidden();
        config(['identity.recent_password_seconds' => 900]);
        $first = $this->browser('POST', $path, [], $headers)->assertOk();
        self::assertSame($first->json(), $this->browser('POST', $path, [], $headers)->assertOk()->json());
        $this->projectCommand($f['author'], $f['project'], 'project.advance');
        $this->browser('GET', '/api/v1/projects/'.$f['project']->id)->assertOk()->assertJsonPath('data.state', 'completed');
        DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('code', 'projects.self.confirm')->value('id'))->delete();
        $this->browser('POST', $path, [], $headers)->assertForbidden();
        $this->assertDatabaseCount('project_completion_confirmations', 1);
    }

    public function test_pagination_filters_and_foreign_milestone_ids_are_scoped(): void
    {
        $a = $this->projectFixture();
        $b = $this->projectFixture();
        $foreign = $this->projectCommand($b['author'], $b['project'], 'project.milestone.create', input: $this->milestoneInput(true));
        $this->staffLogin($a['author']);
        $page = $this->browser('GET', '/api/v1/admin/projects?limit=1')->assertOk()->assertJsonCount(1, 'data');
        $cursor = $page->json('meta.next_after');
        self::assertNotNull($cursor);
        $this->browser('GET', '/api/v1/admin/projects?limit=1&after='.$cursor)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_after', null);
        $this->browser('GET', '/api/v1/admin/projects?state=completed')->assertOk()->assertJsonCount(0, 'data');
        $this->browser('GET', '/api/v1/admin/projects?limit=101')->assertUnprocessable();
        $this->browser('GET', '/api/v1/admin/projects?after=invalid')->assertUnprocessable();
        $base = '/api/v1/admin/projects/'.$a['project']->id;
        $this->browser('POST', $base.'/milestones/'.$foreign['data']['id'].'/starts', [], $this->headers($a['project']))->assertNotFound();
        $created = $this->browser('POST', $base.'/milestones', $this->milestoneInput(true), $this->headers($a['project']))->assertCreated();
        $id = $created->json('data.id');
        $this->browser('POST', $base.'/milestones/'.$id.'/starts', [], $this->headers($a['project']))->assertOk();
        $this->browser('PATCH', $base.'/milestones/'.$id, [...$this->milestoneInput(true), 'state' => 'completed'], $this->headers($a['project']))->assertUnprocessable();
        $this->browser('POST', $base.'/milestones/'.$id.'/completions', [], $this->headers($a['project']))->assertOk();
    }

    public function test_team_replay_preserves_receipt_after_candidate_disable_and_empty_milestone_description_is_valid(): void
    {
        $f = $this->projectFixture();
        $staff = $this->intakeStaff('business_analyst');
        $this->staffLogin($f['author']);
        $base = '/api/v1/admin/projects/'.$f['project']->id;
        $headers = $this->headers($f['project']);
        $input = ['staff_id' => $staff->id, 'role' => 'business_analyst'];
        $first = $this->browser('POST', $base.'/team-members', $input, $headers)->assertCreated();
        $staff->forceFill(['enabled' => false])->save();
        self::assertSame($first->json(), $this->browser('POST', $base.'/team-members', $input, $headers)->assertCreated()->json());
        $this->browser('POST', $base.'/team-members', $input, $this->headers($f['project']))->assertUnprocessable();
        $this->browser('POST', $base.'/milestones', [...$this->milestoneInput(true), 'description' => ''], $this->headers($f['project']))->assertCreated();
        $this->browser('GET', $base.'/milestones')->assertOk()->assertJsonPath('data.0.description', '');
    }

    private function headers(Project $project): array
    {
        $project->refresh();

        return ['If-Match' => VersionPrecondition::etag($project->id, $project->lock_version), 'Idempotency-Key' => (string) Str::uuid7()];
    }

    private function milestoneInput(bool $visible): array
    {
        return ['name' => 'Delivery milestone', 'description' => 'Bounded delivery outcome.', 'display_order' => 1, 'customer_visible' => $visible];
    }

    private function staffLogin(User $user): void
    {
        $this->signIn($user)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }
}
