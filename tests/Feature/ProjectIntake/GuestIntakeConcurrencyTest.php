<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\ProjectIntake\Actions\GuestDocuments;
use App\Modules\ProjectIntake\Actions\GuestDrafts;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitGuestRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class GuestIntakeConcurrencyTest extends TestCase
{
    use CommercialDatabase;
    use IntakeFixtures;

    #[DataProvider('keys')]
    public function test_duplicate_guest_submissions_commit_one_revision(bool $sameKey): void
    {
        $one = $this->submission();
        $two = [...$one, 'key' => $sameKey ? $one['key'] : (string) Str::uuid7()];
        $results = $this->race([$one, $two], 'project_requests', $one['id']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame($sameKey ? [200, 200] : [200, 409], $statuses);
        $this->assertDatabaseCount('request_revisions', 1);
        $this->assertDatabaseCount('intake_notification_intents', 1);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'intake.guest_submitted')->count());
        if ($sameKey) {
            self::assertSame($results[0]['reference'], $results[1]['reference']);
        }
    }

    public static function keys(): array
    {
        return [[true], [false]];
    }

    public function test_submission_and_upload_finalization_cannot_commit_an_incomplete_attachment(): void
    {
        $one = $this->submission();
        $reservation = DB::transaction(function () use ($one) {
            [$record] = app(GuestDrafts::class)->lock($one['id'], $one['capability'], $one['session']);

            return app(GuestDocuments::class)->reserve($record, $one['etag'], 'idea.pdf', 20, str_repeat('a', 64), (string) Str::uuid7(), (string) Str::uuid7());
        });
        $one['etag'] = VersionPrecondition::etag($one['id'], 2);
        $results = $this->race([$one, [...$one, 'action' => 'finalize', 'document_id' => $reservation->documentId]], 'project_requests', $one['id']);
        self::assertSame(200, $results[1]['status']);
        self::assertContains($results[0]['status'], [200, 409]);
        if ($results[0]['status'] === 409) {
            $this->submit($one);
        }
        $this->assertDatabaseCount('request_revisions', 1);
        $this->assertDatabaseCount('intake_revision_documents', 1);
        $this->assertDatabaseHas('documents', ['id' => $reservation->documentId, 'state' => 'quarantined', 'storage_version' => 'g1-race-version']);
    }

    #[DataProvider('keys')]
    public function test_claim_competition_is_one_time_and_exact_replay_is_stable(bool $sameKey): void
    {
        $user = $this->intakeCustomer();
        $guest = $this->submission($user->email);
        $receipt = $this->submit($guest);
        $one = ['action' => 'claim', 'user_id' => $user->id, 'token' => $receipt['claim_token'], 'key' => (string) Str::uuid7()];
        $results = $this->race([$one, [...$one, 'key' => $sameKey ? $one['key'] : (string) Str::uuid7()]], 'project_requests', $guest['id']);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame($sameKey ? [200, 200] : [200, 404], $statuses);
        $this->assertDatabaseCount('intake_guest_claims', 1);
        $this->assertDatabaseCount('request_revisions', 1);
        $this->assertDatabaseHas('request_revisions', ['request_id' => $guest['id'], 'customer_id' => null, 'submitted_by' => null]);
    }

    public function test_different_customers_cannot_race_to_associate_by_token_alone(): void
    {
        $owner = $this->intakeCustomer();
        $other = $this->intakeCustomer();
        $guest = $this->submission($owner->email);
        $receipt = $this->submit($guest);
        $one = ['action' => 'claim', 'user_id' => $owner->id, 'token' => $receipt['claim_token'], 'key' => (string) Str::uuid7()];
        $results = $this->race([$one, [...$one, 'user_id' => $other->id, 'key' => (string) Str::uuid7()]], 'project_requests', $guest['id']);
        self::assertSame([200, 404], array_column($results, 'status'));
        $this->assertDatabaseHas('project_requests', ['id' => $guest['id'], 'customer_user_id' => $owner->id]);
    }

    public function test_claim_and_withdrawal_are_serialized_without_losing_the_claim_or_history(): void
    {
        $user = $this->intakeCustomer();
        $guest = $this->submission($user->email);
        $receipt = $this->submit($guest);
        $one = ['action' => 'claim', 'user_id' => $user->id, 'token' => $receipt['claim_token'], 'key' => (string) Str::uuid7()];
        $two = ['action' => 'withdraw', 'user_id' => $user->id, 'id' => $guest['id'], 'etag' => VersionPrecondition::etag($guest['id'], 2)];
        $results = $this->race([$one, $two], 'users', $user->id);
        self::assertSame(200, $results[0]['status']);
        self::assertContains($results[1]['status'], [404, 412]);
        $this->assertDatabaseHas('project_requests', ['id' => $guest['id'], 'state' => 'submitted', 'customer_user_id' => $user->id]);
        $this->assertDatabaseCount('intake_guest_claims', 1);
    }

    public function test_profile_change_and_authenticated_submission_snapshot_one_coherent_profile(): void
    {
        $user = $this->intakeCustomer();
        $actor = $this->intakeActor($user);
        $draft = app(ManageDraft::class)->create($actor, $this->intakeInput(), (string) Str::uuid7());
        $one = ['action' => 'authenticated_submit', 'user_id' => $user->id, 'id' => $draft->id,
            'etag' => VersionPrecondition::etag($draft->id, 1), 'key' => (string) Str::uuid7()];
        $results = $this->race([$one, ['action' => 'profile', 'user_id' => $user->id]], 'users', $user->id);
        self::assertSame([200, 200], array_column($results, 'status'));
        $revision = DB::table('request_revisions')->where('request_id', $draft->id)->first();
        self::assertContains([$revision->full_name, $revision->phone_e164], [[$user->full_name, '+218912345678'], ['New coherent name', '+12025550199']]);
    }

    private function submission(string $email = 'guest@example.test'): array
    {
        $session = bin2hex(random_bytes(32));
        $draft = app(GuestDrafts::class)->create($session, (string) Str::uuid7());

        return ['action' => 'submit', 'id' => $draft['draft_id'], 'capability' => $draft['capability'], 'session' => $session,
            'etag' => $draft['etag'], 'key' => (string) Str::uuid7(), 'input' => [...$this->intakeInput(), 'full_name' => 'Guest Person', 'email' => $email, 'phone' => '+218912345678']];
    }

    private function submit(array $input): array
    {
        return app(SubmitGuestRequest::class)->handle($input['id'], $input['capability'], $input['session'], $input['etag'], $input['key'], $input['input'], (string) Str::uuid7());
    }

    private function race(array $operations, string $table, string $id): array
    {
        self::assertContains($table, ['project_requests', 'users']);
        $application = 'g1-race-'.Str::uuid7();
        $peers = [];
        DB::beginTransaction();
        try {
            DB::table($table)->where('id', $id)->lockForUpdate()->first();
            $parent = DB::scalar('SELECT pg_backend_pid()');
            foreach ($operations as $index => $operation) {
                $payload = base64_encode(json_encode([...$operation, 'application' => $application.'-'.$index], JSON_THROW_ON_ERROR));
                $peer = new Process([PHP_BINARY, base_path('tests/Fixtures/guest-intake-worker.php'), $payload], base_path(), timeout: 25);
                $peer->start();
                $peers[] = $peer;
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'like', $application.'%')->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                foreach ($peers as $peer) {
                    if (! $peer->isRunning()) {
                        self::fail('Worker exited before lock barrier: '.$peer->getErrorOutput().$peer->getOutput());
                    }
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both independent PostgreSQL connections must wait at the lock barrier.');
            DB::commit();
            $results = [];
            foreach ($peers as $peer) {
                self::assertSame(0, $peer->wait(), $peer->getErrorOutput().$peer->getOutput());
                $result = json_decode($peer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
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
