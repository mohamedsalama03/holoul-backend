<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class IntakeConcurrencyTest extends TestCase
{
    use CommercialDatabase;
    use IntakeFixtures;

    public function test_different_customers_concurrently_receive_distinct_sequence_references(): void
    {
        $first = $this->intakeActor($this->intakeCustomer());
        $second = $this->intakeActor($this->intakeCustomer());
        $input = $this->intakeInput();
        $one = app(ManageDraft::class)->create($first, $input, (string) Str::uuid7());
        $two = app(ManageDraft::class)->create($second, $input, (string) Str::uuid7());

        $results = $this->race([
            $this->operation('submit', $first, $one),
            $this->operation('submit', $second, $two),
        ]);
        self::assertSame([200, 200], array_column($results, 'status'));
        self::assertNotSame($results[0]['reference'], $results[1]['reference']);
        $sequences = [];
        foreach ($results as $result) {
            self::assertMatchesRegularExpression('/^REQ-'.gmdate('Y').'-[0-9]{5,}$/D', $result['reference']);
            $sequences[] = (int) substr($result['reference'], 9);
            $this->assertDatabaseHas('project_requests', ['id' => $result['request_id'], 'reference' => $result['reference'],
                'state' => 'submitted', 'lock_version' => 2, 'latest_revision_number' => 1]);
            $this->assertSubmissionEffects($result['request_id'], 1);
        }
        sort($sequences);
        self::assertSame($sequences[0] + 1, $sequences[1]);
        self::assertSame(2, DB::table('project_requests')->distinct()->count('reference'));
    }

    public function test_same_key_simultaneous_submissions_replay_one_committed_effect(): void
    {
        $actor = $this->intakeActor($this->intakeCustomer());
        $record = app(ManageDraft::class)->create($actor, $this->intakeInput(), (string) Str::uuid7());
        $operation = $this->operation('submit', $actor, $record);
        $results = $this->race([$operation, $operation]);
        self::assertSame([200, 200], array_column($results, 'status'));
        foreach (['request_id', 'revision_id', 'revision_number', 'version', 'state', 'reference'] as $field) {
            self::assertSame($results[0][$field], $results[1][$field], 'A replay returned a different '.$field.'.');
        }
        $this->assertSubmissionEffects($record->id, 1);
        self::assertSame(1, DB::table('intake_submission_keys')->where('request_id', $record->id)->count());
        $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'lock_version' => 2, 'latest_revision_number' => 1]);
        $this->assertDatabaseHas('request_drafts', ['request_id' => $record->id, 'is_open' => false]);
    }

    public function test_submit_and_draft_edit_at_one_version_cannot_commit_mixed_content(): void
    {
        $actor = $this->intakeActor($this->intakeCustomer());
        $input = $this->intakeInput();
        $record = app(ManageDraft::class)->create($actor, $input, (string) Str::uuid7());
        $results = $this->race([
            $this->operation('submit', $actor, $record),
            $this->operation('update', $actor, $record),
        ]);
        $winner = $this->oneWinner($results);
        $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'lock_version' => 2]);
        if ($winner === 'submit') {
            $this->assertSubmissionEffects($record->id, 1);
            $this->assertDatabaseHas('request_revisions', ['request_id' => $record->id, 'project_name' => $input['project_name']]);
            $this->assertDatabaseHas('request_drafts', ['request_id' => $record->id, 'project_name' => $input['project_name'], 'is_open' => false]);
            $this->assertDatabaseMissing('audit_events', ['subject_id' => $record->id, 'event_type' => 'intake.draft_updated']);
        } else {
            self::assertSame('update', $winner);
            $this->assertSubmissionEffects($record->id, 0);
            $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'state' => 'draft', 'reference' => null]);
            $this->assertDatabaseHas('request_drafts', ['request_id' => $record->id, 'project_name' => 'Concurrent draft edit', 'is_open' => true]);
            self::assertSame(1, DB::table('audit_events')->where('subject_id', $record->id)->where('event_type', 'intake.draft_updated')->count());
            self::assertSame(0, DB::table('intake_submission_keys')->where('request_id', $record->id)->count());
        }
    }

    public function test_amendment_submission_and_withdrawal_at_one_version_commit_only_one_transition(): void
    {
        $user = $this->intakeCustomer();
        $actor = $this->intakeActor($user);
        $record = $this->createSubmitted($user);
        $reference = $record->reference;
        $record = app(ManageDraft::class)->amend($actor, $record->id,
            VersionPrecondition::etag($record->id, $record->lock_version), (string) Str::uuid7());
        $results = $this->race([
            $this->operation('submit', $actor, $record),
            $this->operation('withdraw', $actor, $record),
        ]);
        $winner = $this->oneWinner($results);
        $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'lock_version' => 4, 'reference' => $reference]);
        $this->assertDatabaseHas('request_drafts', ['request_id' => $record->id, 'is_open' => false]);
        $revisions = $winner === 'submit' ? 2 : 1;
        self::assertSame($revisions, DB::table('request_revisions')->where('request_id', $record->id)->count());
        self::assertSame($revisions, DB::table('intake_notification_intents')->where('request_id', $record->id)->count());
        if ($winner === 'submit') {
            $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'state' => 'submitted', 'latest_revision_number' => 2]);
            self::assertSame(1, DB::table('request_state_changes')->where('request_id', $record->id)->count());
            self::assertSame(1, DB::table('audit_events')->where('subject_id', $record->id)->where('event_type', 'intake.amendment_submitted')->count());
            $this->assertDatabaseMissing('audit_events', ['subject_id' => $record->id, 'event_type' => 'intake.withdrawn']);
        } else {
            self::assertSame('withdraw', $winner);
            $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'state' => 'withdrawn', 'latest_revision_number' => 1]);
            self::assertSame(2, DB::table('request_state_changes')->where('request_id', $record->id)->count());
            self::assertSame(1, DB::table('audit_events')->where('subject_id', $record->id)->where('event_type', 'intake.withdrawn')->count());
            $this->assertDatabaseMissing('audit_events', ['subject_id' => $record->id, 'event_type' => 'intake.amendment_submitted']);
        }
    }

    private function assertSubmissionEffects(string $id, int $expected): void
    {
        foreach (['request_revisions', 'intake_notification_intents', 'request_state_changes'] as $table) {
            self::assertSame($expected, DB::table($table)->where('request_id', $id)->count(), $table);
        }
        self::assertSame($expected, DB::table('audit_events')->where('subject_id', $id)->where('event_type', 'intake.submitted')->count());
    }

    /** @param list<array<string,mixed>> $results */
    private function oneWinner(array $results): string
    {
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame([200, 412], $statuses);
        $winners = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 200));
        self::assertCount(1, $winners);

        return $winners[0]['action'];
    }

    /** @return array{action:string,actor_id:string,customer_id:string,id:string,etag:string,key:string} */
    private function operation(string $action, IntakeActor $actor, ProjectRequest $record): array
    {
        self::assertNotNull($actor->customerId);

        return ['action' => $action, 'actor_id' => $actor->id, 'customer_id' => $actor->customerId, 'id' => $record->id,
            'etag' => VersionPrecondition::etag($record->id, $record->lock_version), 'key' => (string) Str::uuid7()];
    }

    /**
     * @param  list<array{action:string,actor_id:string,customer_id:string,id:string,etag:string,key:string}>  $operations
     * @return list<array<string,mixed>>
     */
    private function race(array $operations): array
    {
        self::assertCount(2, $operations);
        $application = 'intake-race-'.Str::uuid7();
        $peers = [];
        DB::beginTransaction();
        try {
            DB::table('project_requests')->whereIn('id', array_column($operations, 'id'))->orderBy('id')->lockForUpdate()->get();
            $parentBackend = DB::scalar('SELECT pg_backend_pid()');
            foreach ($operations as $index => $operation) {
                $payload = base64_encode(json_encode($operation + ['application' => $application.'-'.$index], JSON_THROW_ON_ERROR));
                $peer = new Process([PHP_BINARY, base_path('tests/Fixtures/intake-concurrency-worker.php'), $payload], base_path(), timeout: 20);
                $peer->start();
                $peers[] = $peer;
            }
            $deadline = microtime(true) + 8;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'like', $application.'%')
                    ->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                foreach ($peers as $peer) {
                    if (! $peer->isRunning()) {
                        self::fail('Worker exited before the barrier: '.$peer->getErrorOutput().$peer->getOutput());
                    }
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both independent database processes must be waiting before the parent releases its row locks.');
            DB::commit();
            $results = [];
            foreach ($peers as $peer) {
                self::assertSame(0, $peer->wait(), $peer->getErrorOutput());
                $result = json_decode($peer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                self::assertNotSame($parentBackend, $result['backend']);
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
