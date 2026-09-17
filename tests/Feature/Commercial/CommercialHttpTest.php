<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\AssignRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\CommercialDatabase;
use Tests\Support\CommercialFixtures;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

final class CommercialHttpTest extends TestCase
{
    use CommercialDatabase;
    use CommercialFixtures;
    use IdentityHttp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBrowser();
    }

    public function test_customer_http_acceptance_requires_csrf_origin_version_and_idempotency_and_replays_exactly(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $path = '/api/v1/project-requests/'.$f['request']->id.'/proposals/'.$p->id;
        $this->browser('GET', $path)->assertUnauthorized();
        $this->signIn($f['customer'])->assertOk();
        $view = $this->browser('GET', $path)->assertOk()->assertJsonPath('data.amount', '30.369')->assertJsonPath('data.items.0.line_total', '30.369');
        self::assertStringNotContainsString('Staff-only notes.', $view->getContent());
        self::assertStringNotContainsString('Private reviewer notes.', $view->getContent());
        $headers = ['If-Match' => $view->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()];
        $this->browser('POST', $path.'/acceptances', [], $headers, false)->assertForbidden();
        $this->browser('POST', $path.'/acceptances', [], [...$headers, 'Origin' => 'https://foreign.test'])->assertForbidden();
        $this->browser('POST', $path.'/acceptances', [], ['Idempotency-Key' => (string) Str::uuid7()])->assertStatus(428);
        $this->browser('POST', $path.'/acceptances', [], ['If-Match' => $headers['If-Match']])->assertUnprocessable();
        $first = $this->browser('POST', $path.'/acceptances', [], $headers)->assertOk()->assertJsonPath('data.state', 'accepted');
        $repeat = $this->browser('POST', $path.'/acceptances', [], $headers)->assertOk();
        self::assertSame($first->json(), $repeat->json());
        self::assertSame($first->headers->get('ETag'), $repeat->headers->get('ETag'));
        $this->assertDatabaseCount('proposal_decisions', 1);
        $this->browser('POST', '/api/v1/project-requests/'.$f['request']->id.'/withdrawals', ['message' => 'Customer cancellation'], ['If-Match' => $first->headers->get('ETag')])
            ->assertOk()->assertJsonPath('data.state', 'withdrawn');
        $this->assertDatabaseHas('proposal_decisions', ['proposal_id' => $p->id, 'decision' => 'rescinded']);
    }

    public function test_customer_isolation_draft_visibility_nested_ids_and_malformed_ids(): void
    {
        $a = $this->commercialFixture();
        $p = $this->issueProposal($a);
        $draft = $this->commercialCommand($a['author'], $a['request'], 'proposal.create', input: $this->commercialTerms($p->discovery_revision_id));
        $b = $this->commercialFixture();
        $foreign = $this->issueProposal($b);
        $this->signIn($a['customer'])->assertOk();
        $base = '/api/v1/project-requests/'.$a['request']->id;
        $this->browser('GET', $base.'/proposals')->assertOk()->assertJsonCount(1, 'data');
        $this->browser('GET', $base.'/proposals/'.$draft['data']['id'])->assertNotFound();
        foreach ([$foreign->id, 'bad-id', (string) Str::uuid()] as $id) {
            $this->browser('GET', $base.'/proposals/'.$id)->assertNotFound()->assertJsonMissingPath('exception');
            $this->browser('POST', $base.'/proposals/'.$id.'/acceptances', [], ['If-Match' => $this->commercialEtag($a['request']->refresh()), 'Idempotency-Key' => (string) Str::uuid7()])->assertNotFound();
        }
        $other = '/api/v1/project-requests/'.$b['request']->id.'/proposals/'.$foreign->id;
        $this->browser('GET', $other)->assertNotFound();
        $this->browser('POST', $other.'/declines', [], ['If-Match' => $this->commercialEtag($b['request']->refresh()), 'Idempotency-Key' => (string) Str::uuid7()])->assertNotFound();
        $this->browser('GET', '/api/v1/admin/project-requests/'.$a['request']->id.'/discovery/'.$p->discovery_revision_id)->assertForbidden();
        $this->browser('PATCH', $base.'/proposals/'.$p->id, ['state' => 'accepted'])->assertStatus(405);
        $this->assertDatabaseCount('proposal_decisions', 0);
    }

    #[DataProvider('forgedFields')]
    public function test_forged_customer_decision_fields_are_rejected(string $field, mixed $value): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $this->signIn($f['customer'])->assertOk();
        $this->browser('POST', '/api/v1/project-requests/'.$f['request']->id.'/proposals/'.$p->id.'/acceptances', [$field => $value],
            ['If-Match' => $this->commercialEtag($f['request']->refresh()), 'Idempotency-Key' => (string) Str::uuid7()])->assertUnprocessable();
        $this->assertDatabaseCount('proposal_decisions', 0);
    }

    public static function forgedFields(): array
    {
        return [['customer_id', 'forged'], ['decided_by', 'forged'], ['state', 'accepted'], ['amount', '0'], ['approved_by', 'forged'], ['issued_at', '2020-01-01'], ['lock_version', 999]];
    }

    #[DataProvider('staffMatrix')]
    public function test_staff_permission_matrix_and_assignment_are_enforced_with_real_mfa(string $role, bool $discovery, bool $proposal, bool $manage, bool $approve, bool $issue): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $staff = $this->intakeStaff($role);
        app(AssignRequest::class)->handle($this->intakeActor($f['author']), $f['request']->id, $this->commercialEtag($f['request']->refresh()), $staff->id, (string) Str::uuid7());
        $this->signInStaff($staff);
        $base = '/api/v1/admin/project-requests/'.$f['request']->id;
        $this->browser('GET', $base.'/discovery/'.$p->discovery_revision_id)->assertStatus($discovery ? 200 : 403);
        $this->browser('GET', $base.'/proposals/'.$p->id)->assertStatus($proposal ? 200 : 403);
        $headers = ['If-Match' => $this->commercialEtag($f['request']->refresh()), 'Idempotency-Key' => (string) Str::uuid7()];
        // Authorized but wrong phase is a conflict; missing permission is forbidden.
        $this->browser('POST', $base.'/discovery', ['summary' => 'New', 'internal_notes' => 'Notes'], $headers)->assertStatus($manage ? 409 : 403);
        $this->browser('POST', $base.'/proposals/'.$p->id.'/approvals', [], $headers)->assertStatus($approve ? 409 : 403);
        $this->browser('POST', $base.'/proposals/'.$p->id.'/issuances', [], $headers)->assertStatus($issue ? 409 : 403);
        $this->browser('POST', '/api/v1/project-requests/'.$f['request']->id.'/proposals/'.$p->id.'/acceptances', [], $headers)->assertForbidden();
        if ($role !== 'super_admin' && $proposal) {
            app(AssignRequest::class)->handle($this->intakeActor($f['author']), $f['request']->id, $this->commercialEtag($f['request']->refresh()), $f['author']->id, (string) Str::uuid7());
            $this->browser('GET', $base.'/proposals/'.$p->id)->assertNotFound();
        }
    }

    public static function staffMatrix(): array
    {
        return [['super_admin', true, true, true, true, true], ['project_manager', true, true, true, true, true],
            ['business_analyst', true, true, true, false, false], ['sales', true, true, false, false, true],
            ['reviewer', true, false, false, false, false], ['administrator', false, false, false, false, false], ['support', false, false, false, false, false]];
    }

    public function test_expired_recent_password_revoked_sessions_and_changed_permissions_fail_closed(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $this->signIn($f['customer'])->assertOk();
        $path = '/api/v1/project-requests/'.$f['request']->id.'/proposals/'.$p->id.'/acceptances';
        $headers = ['If-Match' => $this->commercialEtag($f['request']->refresh()), 'Idempotency-Key' => (string) Str::uuid7()];
        config(['identity.recent_password_seconds' => 0]);
        $this->browser('POST', $path, [], $headers)->assertForbidden();
        config(['identity.recent_password_seconds' => 900]);
        DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('code', 'proposals.self.accept')->value('id'))->delete();
        $this->browser('POST', $path, [], $headers)->assertForbidden();
        DB::table('identity_sessions')->where('user_id', $f['customer']->id)->delete();
        $this->browser('POST', $path, [], $headers)->assertUnauthorized();
        $this->assertDatabaseCount('proposal_decisions', 0);
    }

    public function test_optional_notes_and_descriptions_accept_empty_or_omitted_http_fields(): void
    {
        $f = $this->commercialFixture();
        $this->signInStaff($f['author']);
        $base = '/api/v1/admin/project-requests/'.$f['request']->id;
        $headers = fn (): array => ['If-Match' => $this->commercialEtag($f['request']->refresh()), 'Idempotency-Key' => (string) Str::uuid7()];
        $created = $this->browser('POST', $base.'/discovery', ['summary' => 'Practical scope', 'internal_notes' => ''], $headers())->assertCreated();
        $id = $created->json('data.id');
        $created->assertHeader('Location', $base.'/discovery/'.$id);
        $requirements = $this->commercialRequirements();
        unset($requirements['requirements'][0]['notes']);
        $this->browser('PUT', $base.'/discovery/'.$id.'/requirements', $requirements, $headers())->assertOk();
        $this->browser('POST', $base.'/discovery/'.$id.'/starts', [], $headers())->assertOk();
        $this->browser('POST', $base.'/discovery/'.$id.'/completions', [], $headers())->assertOk();
        $this->browser('GET', $base.'/discovery/'.$id)->assertOk()->assertJsonPath('data.internal_notes', '')->assertJsonPath('data.requirements.0.notes', '');
        $terms = $this->commercialTerms($id);
        unset($terms['commercial_notes']);
        $terms['items'][0]['description'] = '';
        $proposal = $this->browser('POST', $base.'/proposals', $terms, $headers())->assertCreated()->json('data.id');
        $this->browser('GET', $base.'/proposals/'.$proposal)->assertOk()->assertJsonPath('data.commercial_notes', '')->assertJsonPath('data.items.0.description', '');
    }

    private function signInStaff(User $user): void
    {
        $this->signIn($user)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }
}
