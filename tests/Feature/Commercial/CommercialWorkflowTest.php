<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Application\Commercial\WithdrawCommercialRequest;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\Proposals\Models\Proposal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\CommercialDatabase;
use Tests\Support\CommercialFixtures;
use Tests\TestCase;

final class CommercialWorkflowTest extends TestCase
{
    use CommercialDatabase;
    use CommercialFixtures;

    public function test_discovery_lifecycle_signoff_and_new_revision_preserve_the_completed_baseline(): void
    {
        $f = $this->commercialFixture();
        $id = $this->completeDiscovery($f['author'], $f['request']);
        $before = DB::table('discovery_revisions')->where('id', $id)->sole();
        $this->assertDatabaseHas('discovery_signoffs', ['revision_id' => $id, 'revision_version' => 4, 'completed_by' => $f['author']->id]);
        $this->reject(409, fn () => $this->commercialCommand($f['author'], $f['request'], 'discovery.update', $id, ['summary' => 'Rewrite', 'internal_notes' => 'Notes']));
        $this->reject(409, fn () => $this->commercialCommand($f['author'], $f['request'], 'discovery.requirements', $id, $this->commercialRequirements()));
        $next = $this->commercialCommand($f['author'], $f['request'], 'discovery.create', input: ['summary' => 'Changed requirements', 'internal_notes' => 'New revision']);
        self::assertSame(2, $next['data']['revision_number']);
        self::assertEquals($before, DB::table('discovery_revisions')->where('id', $id)->sole());
        self::assertSame(1, DB::table('discovery_requirements')->where('revision_id', $id)->count());
    }

    public function test_discovery_requires_handoff_start_and_resolved_requirements(): void
    {
        $f = $this->commercialFixture();
        $result = $this->commercialCommand($f['author'], $f['request'], 'discovery.create', input: ['summary' => 'Summary', 'internal_notes' => 'Notes']);
        $id = $result['data']['id'];
        $this->reject(409, fn () => $this->commercialCommand($f['author'], $f['request'], 'discovery.complete', $id));
        $this->commercialCommand($f['author'], $f['request'], 'discovery.start', $id);
        $this->reject(409, fn () => $this->commercialCommand($f['author'], $f['request'], 'discovery.complete', $id));
        $requirements = $this->commercialRequirements();
        $requirements['requirements'][0]['status'] = 'proposed';
        $this->commercialCommand($f['author'], $f['request'], 'discovery.requirements', $id, $requirements);
        $this->reject(409, fn () => $this->commercialCommand($f['author'], $f['request'], 'discovery.complete', $id));
        $this->assertDatabaseCount('discovery_signoffs', 0);
        $this->reject(409, fn () => $this->commercialCommand($f['author'], $f['request'], 'proposal.create', input: $this->commercialTerms($id)));
    }

    #[DataProvider('currencies')]
    public function test_issued_proposal_acceptance_is_atomic_exact_and_idempotent(string $currency, int $amount): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f, $currency);
        self::assertSame($amount, $p->amount_minor);
        self::assertMatchesRegularExpression('/^PROP-[0-9]{4}-[0-9]{5,19}$/', $p->number ?? '');
        self::assertSame(RequestState::Proposal, $f['request']->refresh()->state);
        $etag = $this->commercialEtag($f['request']);
        $key = (string) Str::uuid7();
        $first = $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id, etag: $etag, key: $key);
        $replay = $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id, etag: $etag, key: $key);
        self::assertSame($first, $replay);
        self::assertSame('accepted', $p->refresh()->state);
        self::assertSame(RequestState::Approved, $f['request']->refresh()->state);
        $this->assertDatabaseCount('proposal_decisions', 1);
        $this->assertDatabaseHas('proposal_decisions', ['proposal_id' => $p->id, 'decision' => 'accepted', 'decided_by' => $f['customer']->id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'proposals.accepted', 'subject_id' => $p->id, 'actor_id' => $f['customer']->id]);
        $this->reject(409, fn () => $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id));
        $this->reject(409, fn () => $this->commercialCommand($f['customer'], $f['request'], 'proposal.decline', $p->id, etag: $etag, key: $key));
        $audit = json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Private issued commercial scope.', $audit);
        self::assertStringNotContainsString('Private structured requirement.', $audit);
    }

    public static function currencies(): array
    {
        return [['USD', 3036], ['LYD', 30369]];
    }

    public function test_any_material_edit_invalidates_approval_and_all_contributors_cannot_approve(): void
    {
        $f = $this->commercialFixture();
        $baseline = $this->completeDiscovery($f['author'], $f['request']);
        $terms = $this->commercialTerms($baseline);
        $created = $this->commercialCommand($f['author'], $f['request'], 'proposal.create', input: $terms);
        $id = $created['data']['id'];
        $this->reject(403, fn () => $this->commercialCommand($f['author'], $f['request'], 'proposal.approve', $id));
        $this->reject(409, fn () => $this->commercialCommand($f['author'], $f['request'], 'proposal.issue', $id));
        $this->commercialCommand($f['approver'], $f['request'], 'proposal.approve', $id);
        $terms['timeline'] = 'Eight weeks';
        $this->commercialCommand($f['approver'], $f['request'], 'proposal.update', $id, $terms);
        $p = Proposal::query()->findOrFail($id);
        self::assertSame('draft', $p->state);
        self::assertNull($p->current_approval_id);
        self::assertSame(2, $p->content_version);
        $this->assertDatabaseCount('proposal_approvals', 1);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'proposals.approval_invalidated', 'subject_id' => $id]);
        $this->reject(403, fn () => $this->commercialCommand($f['approver'], $f['request'], 'proposal.approve', $id));
        $this->reject(409, fn () => $this->commercialCommand($f['author'], $f['request'], 'proposal.issue', $id));
        $third = $this->intakeStaff('super_admin');
        $this->commercialCommand($third, $f['request'], 'proposal.approve', $id);
        $this->commercialCommand($f['author'], $f['request'], 'proposal.issue', $id);
        $this->reject(409, fn () => $this->commercialCommand($f['author'], $f['request'], 'proposal.update', $id, $terms));
    }

    #[DataProvider('closures')]
    public function test_terminal_proposal_actions_preserve_terms_and_return_request_to_discovery(string $operation, string $state): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $terms = $p->only(['scope_summary', 'timeline', 'commercial_notes', 'amount_minor', 'currency', 'number', 'issued_at', 'discovery_revision_id']);
        $actor = $operation === 'proposal.decline' ? $f['customer'] : $f['author'];
        $this->commercialCommand($actor, $f['request'], $operation, $p->id, ['reason' => 'Changed needs.']);
        self::assertSame($state, $p->refresh()->state);
        self::assertEquals($terms, $p->only(array_keys($terms)));
        self::assertSame(RequestState::Discovery, $f['request']->refresh()->state);
        $this->reject(409, fn () => $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id));
        $this->assertDatabaseHas('audit_events', ['event_type' => 'proposals.'.$state, 'subject_id' => $p->id]);
        $new = $this->issueProposal($f);
        self::assertSame(2, $new->revision_number);
        self::assertNotSame($p->number, $new->number);
        self::assertNotSame($p->discovery_revision_id, $new->discovery_revision_id);
    }

    public static function closures(): array
    {
        return [['proposal.decline', 'declined'], ['proposal.withdraw', 'withdrawn'], ['proposal.supersede', 'superseded']];
    }

    public function test_unverified_stale_auth_and_staff_cannot_accept(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $this->reject(403, fn () => $this->commercialCommand($f['author'], $f['request'], 'proposal.accept', $p->id));
        $this->reject(403, fn () => $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id, recent: false));
        $f['customer']->forceFill(['email_verified_at' => null])->save();
        $this->reject(403, fn () => $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id));
        $this->assertDatabaseCount('proposal_decisions', 0);
        self::assertSame('issued', $p->refresh()->state);
    }

    public function test_proposal_revision_and_etag_guards_prevent_stale_issuance(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $etag = $this->commercialEtag($f['request']->refresh());
        $draft = $this->commercialCommand($f['author'], $f['request'], 'proposal.create', input: $this->commercialTerms($p->discovery_revision_id));
        $this->reject(412, fn () => $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id, etag: $etag));
        $this->commercialCommand($f['approver'], $f['request'], 'proposal.approve', $draft['data']['id']);
        $this->reject(409, fn () => $this->commercialCommand($f['author'], $f['request'], 'proposal.issue', $draft['data']['id']));
        // A newer unissued draft does not silently revoke the current issued offer.
        $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id);
        self::assertSame(RequestState::Approved, $f['request']->refresh()->state);
    }

    public function test_withdrawal_after_acceptance_appends_rescission_without_erasing_the_decision(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id);
        DB::transaction(function () use ($f): void {
            $actor = $this->intakeActor($f['customer']);
            $record = app(IntakeStore::class)->find($actor, $f['request']->id, true);
            app(WithdrawCommercialRequest::class)->handle($actor, $record, true, 'Customer cancelled.', (string) Str::uuid7());
        });
        self::assertSame('rescinded', $p->refresh()->state);
        self::assertSame(RequestState::Withdrawn, $f['request']->refresh()->state);
        self::assertSame(['accepted', 'rescinded'], DB::table('proposal_decisions')->where('proposal_id', $p->id)->orderBy('decided_at')->pluck('decision')->all());
    }

    private function reject(int $status, callable $action): void
    {
        try {
            $action();
            self::fail('Expected command rejection.');
        } catch (AuthorizationException $e) {
            self::assertSame(403, $status);
        } catch (HttpExceptionInterface $e) {
            self::assertSame($status, $e->getStatusCode());
        }
    }
}
