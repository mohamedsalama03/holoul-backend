<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Infrastructure\Clock\DatabaseClock;
use App\Modules\ProjectIntake\Data\RequestState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\CommercialFixtures;
use Tests\TestCase;

final class CommercialConcurrencyTest extends TestCase
{
    use CommercialDatabase;
    use CommercialFixtures;

    #[DataProvider('competingActions')]
    public function test_acceptance_has_one_winner_against_competing_commercial_commands(string $action): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $base = ['request' => $f['request']->id, 'proposal' => $p->id, 'etag' => $this->commercialEtag($f['request']->refresh())];
        $results = $this->race([
            [...$base, 'actor' => $f['customer']->id, 'action' => 'proposal.accept', 'key' => (string) Str::uuid7()],
            [...$base, 'actor' => $action === 'request.withdraw' || $action === 'proposal.accept' ? $f['customer']->id : $f['author']->id, 'action' => $action, 'key' => (string) Str::uuid7()],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame([200, 412], $statuses);
        $p->refresh();
        $f['request']->refresh();
        $accepted = $p->state === 'accepted';
        self::assertSame($accepted ? 1 : 0, DB::table('proposal_decisions')->where('proposal_id', $p->id)->count());
        self::assertSame($accepted ? RequestState::Approved : ($action === 'request.withdraw' ? RequestState::Withdrawn : RequestState::Discovery), $f['request']->state);
        self::assertSame(1, DB::table('proposal_events')->where('proposal_id', $p->id)->whereIn('event', ['accepted', 'withdrawn', 'superseded'])->count());
        self::assertSame($accepted ? 1 : 0, DB::table('audit_events')->where('subject_id', $p->id)->where('event_type', 'proposals.accepted')->count());
    }

    public static function competingActions(): array
    {
        return [['proposal.withdraw'], ['proposal.supersede'], ['request.withdraw'], ['proposal.accept']];
    }

    public function test_duplicate_acceptance_with_the_same_idempotency_key_replays_one_decision(): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f);
        $op = ['request' => $f['request']->id, 'proposal' => $p->id, 'etag' => $this->commercialEtag($f['request']->refresh()),
            'actor' => $f['customer']->id, 'action' => 'proposal.accept', 'key' => (string) Str::uuid7()];
        $results = $this->race([$op, $op]);
        self::assertSame([200, 200], array_column($results, 'status'));
        self::assertSame('accepted', $p->refresh()->state);
        $this->assertDatabaseCount('proposal_decisions', 1);
        self::assertSame(1, DB::table('proposal_command_keys')->where('operation', 'proposal.accept')->count());
    }

    #[DataProvider('expiryTiming')]
    public function test_acceptance_and_expiry_recheck_database_time_after_the_request_lock(bool $releaseAfterExpiry): void
    {
        $f = $this->commercialFixture();
        $p = $this->issueProposal($f, validSeconds: $releaseAfterExpiry ? 3 : 60);
        $base = ['request' => $f['request']->id, 'proposal' => $p->id, 'etag' => $this->commercialEtag($f['request']->refresh()), 'actor' => $f['customer']->id];
        $results = $this->race([
            [...$base, 'action' => 'proposal.accept', 'key' => (string) Str::uuid7()],
            [...$base, 'action' => 'expire', 'key' => (string) Str::uuid7()],
        ], $releaseAfterExpiry ? $p->valid_until->format('Y-m-d H:i:s.uP') : null);
        $p->refresh();
        self::assertSame($releaseAfterExpiry ? 'expired' : 'accepted', $p->state);
        self::assertSame($releaseAfterExpiry ? 0 : 1, DB::table('proposal_decisions')->count());
        $expiry = array_values(array_filter($results, fn (array $r): bool => $r['action'] === 'expire'))[0];
        self::assertSame($releaseAfterExpiry ? 200 : 409, $expiry['status']);
        $accept = array_values(array_filter($results, fn (array $r): bool => $r['action'] === 'proposal.accept'))[0];
        self::assertContains($accept['status'], $releaseAfterExpiry ? [409, 412] : [200]);
    }

    public static function expiryTiming(): array
    {
        return [[false], [true]];
    }

    public function test_parallel_issuance_allocates_unique_immutable_sequence_numbers_for_distinct_requests(): void
    {
        $operations = [];
        foreach ([1, 2] as $index) {
            $f = $this->commercialFixture();
            $baseline = $this->completeDiscovery($f['author'], $f['request']);
            $created = $this->commercialCommand($f['author'], $f['request'], 'proposal.create', input: $this->commercialTerms($baseline));
            $id = $created['data']['id'];
            $this->commercialCommand($f['approver'], $f['request'], 'proposal.approve', $id);
            $operations[] = ['request' => $f['request']->id, 'proposal' => $id, 'etag' => $this->commercialEtag($f['request']->refresh()),
                'actor' => $f['author']->id, 'action' => 'proposal.issue', 'key' => (string) Str::uuid7()];
        }
        $results = $this->race($operations);
        self::assertSame([200, 200], array_column($results, 'status'));
        $numbers = DB::table('proposals')->whereIn('id', array_column($operations, 'proposal'))->pluck('number')->all();
        self::assertCount(2, array_unique($numbers));
        foreach ($numbers as $number) {
            self::assertMatchesRegularExpression('/^PROP-[0-9]{4}-[0-9]{5,19}$/', $number);
        }
        DB::beginTransaction();
        $reserved = DB::scalar("SELECT nextval('proposal_reference_sequence')");
        DB::rollBack();
        self::assertGreaterThan($reserved, DB::scalar("SELECT nextval('proposal_reference_sequence')"));
    }

    private function race(array $operations, ?string $releaseAt = null): array
    {
        $application = 'commercial-race-'.Str::uuid7();
        $peers = [];
        DB::beginTransaction();
        try {
            DB::table('project_requests')->whereIn('id', array_column($operations, 'request'))->orderBy('id')->lockForUpdate()->get();
            $parent = DB::scalar('SELECT pg_backend_pid()');
            foreach ($operations as $index => $op) {
                $payload = base64_encode(json_encode([...$op, 'application' => $application.'-'.$index], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/commercial-concurrency-worker.php'), $payload], base_path(), timeout: 20);
                $process->start();
                $peers[] = $process;
            }
            $deadline = microtime(true) + 8;
            $waiting = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'like', $application.'%')->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                foreach ($peers as $peer) {
                    if (! $peer->isRunning()) {
                        self::fail($peer->getErrorOutput().$peer->getOutput());
                    }
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both independent PostgreSQL workers must wait on the parent lock.');
            if ($releaseAt !== null) {
                while (DatabaseClock::now()->lessThanOrEqualTo($releaseAt)) {
                    usleep(20_000);
                }
            }
            DB::commit();
            $results = [];
            foreach ($peers as $peer) {
                self::assertSame(0, $peer->wait(), $peer->getErrorOutput().$peer->getOutput());
                $result = json_decode($peer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                self::assertNotSame($parent, $result['backend']);
                $results[] = $result;
            }
            self::assertNotSame($results[0]['backend'], $results[1]['backend']);

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($peers as $peer) {
                if ($peer->isRunning()) {
                    $peer->stop(0);
                }
            }
        }
    }
}
