<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Application\Commercial\ExpireProposals;
use App\Infrastructure\Clock\DatabaseClock;
use App\Modules\ProjectIntake\Data\RequestState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\CommercialDatabase;
use Tests\Support\CommercialFixtures;
use Tests\TestCase;

final class CommercialAtomicityTest extends TestCase
{
    use CommercialDatabase;
    use CommercialFixtures;

    public function test_a_real_audit_insert_failure_rolls_back_acceptance_history_receipt_and_request_state(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $version = $f['request']->refresh()->lock_version;
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION b5_test_fail_acceptance_audit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.event_type='proposals.accepted' THEN RAISE EXCEPTION 'Injected audit failure.' USING ERRCODE='P0001'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER b5_test_audit_failure BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION b5_test_fail_acceptance_audit();
            SQL);
        try {
            try {
                $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id);
                self::fail('Audit failure was ignored.');
            } catch (QueryException $e) {
                self::assertSame('P0001', $e->getCode());
            }
        } finally {
            DB::unprepared('DROP TRIGGER b5_test_audit_failure ON audit_events; DROP FUNCTION b5_test_fail_acceptance_audit();');
        }
        self::assertSame('issued', $p->refresh()->state);
        self::assertSame(RequestState::Proposal, $f['request']->refresh()->state);
        self::assertSame($version, $f['request']->lock_version);
        $this->assertDatabaseCount('proposal_decisions', 0);
        $this->assertDatabaseMissing('proposal_command_keys', ['operation' => 'proposal.accept']);
        $this->assertDatabaseMissing('proposal_events', ['proposal_id' => $p->id, 'event' => 'accepted']);
        $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id);
        self::assertSame('accepted', $p->refresh()->state);
    }

    public function test_expiry_is_bounded_idempotent_audited_and_blocks_acceptance_without_a_running_scheduler(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f, validSeconds: 2);
        while (DatabaseClock::now()->lessThanOrEqualTo($p->valid_until)) {
            usleep(20_000);
        }
        try {
            $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id);
            self::fail('Expired proposal was accepted.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        self::assertSame(1, app(ExpireProposals::class)->handle(1));
        self::assertSame(0, app(ExpireProposals::class)->handle(1));
        self::assertSame('expired', $p->refresh()->state);
        self::assertSame(RequestState::Discovery, $f['request']->refresh()->state);
        $this->assertDatabaseCount('proposal_decisions', 0);
        $this->assertDatabaseHas('proposal_events', ['proposal_id' => $p->id, 'event' => 'expired', 'actor_id' => null]);
        $this->assertDatabaseHas('audit_events', ['subject_id' => $p->id, 'event_type' => 'proposals.expired', 'actor_id' => null]);
        $this->artisan('proposals:expire', ['--limit' => 0])->assertExitCode(2);
        $this->artisan('proposals:expire', ['--limit' => 1001])->assertExitCode(2);
    }

    public function test_create_requires_approved_intake_discovery_handoff_and_transactional_stale_version_guard(): void
    {
        $owner = $this->intakeCustomer();
        $staff = $this->intakeStaff('super_admin');
        $request = $this->createSubmitted($owner);
        try {
            $this->commercialCommand($staff, $request, 'discovery.create', input: ['summary' => 'Too soon', 'internal_notes' => 'Notes']);
            self::fail('Missing handoff accepted.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('discovery_records', 0);
        $f = $this->commercialFixture();
        $tag = $this->commercialEtag($f['request']);
        $created = $this->commercialCommand($f['author'], $f['request'], 'discovery.create', input: ['summary' => 'Summary', 'internal_notes' => 'Notes']);
        try {
            $this->commercialCommand($f['author'], $f['request'], 'discovery.start', $created['data']['id'], etag: $tag);
            self::fail('Stale discovery update accepted.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(412, $e->getStatusCode());
        }
        $this->assertDatabaseHas('discovery_revisions', ['id' => $created['data']['id'], 'state' => 'draft', 'lock_version' => 1]);
    }

    public function test_expired_replay_window_preserves_business_uniqueness_and_requires_a_new_key(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $etag = $this->commercialEtag($f['request']->refresh());
        $key = (string) Str::uuid7();
        $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id, etag: $etag, key: $key);
        $row = (array) DB::table('proposal_command_keys')->where('operation', 'proposal.accept')->sole();
        $historicalKey = (string) Str::uuid7();
        DB::table('proposal_command_keys')->insert([...$row, 'id' => (string) Str::uuid7(), 'key_hash' => hash('sha256', $historicalKey),
            'created_at' => DatabaseClock::now()->subHours(73), 'expires_at' => DatabaseClock::now()->subHour()]);
        try {
            $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $p->id, etag: $etag, key: $historicalKey);
            self::fail('Expired receipt replayed.');
        } catch (HttpExceptionInterface $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('proposal_decisions', 1);
        self::assertSame('accepted', $p->refresh()->state);
    }
}
