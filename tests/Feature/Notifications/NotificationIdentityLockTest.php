<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Actions\ReadActiveIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class NotificationIdentityLockTest extends TestCase
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

    #[DataProvider('readers')]
    public function test_business_notification_recipient_fk_does_not_deadlock_a_waiting_authorized_reader(string $mode): void
    {
        $fixture = $this->projectFixture();
        $this->signIn($fixture['customer'])->assertOk();
        $peer = null;
        DB::beginTransaction();
        try {
            DB::statement("SET LOCAL lock_timeout='3s'");
            DB::table('projects')->where('id', $fixture['project']->id)->lockForUpdate()->firstOrFail();
            $parent = DB::scalar('SELECT pg_backend_pid()');
            [$peer, $application] = $this->startWorker($fixture, $mode);
            $readerBackend = $this->waitForLock($peer, $application, 'projects', $parent);
            $result = $this->projectCommand($fixture['author'], $fixture['project'], 'project.update.publish', input: ['content' => 'Concurrent customer-safe update.']);
            self::assertIsString($result['data']['id']);
            self::assertTrue($peer->isRunning(), 'The reader still waits for the business commit, not a retry.');
            self::assertSame(1, DB::table('notifications')->where('recipient_id', $fixture['customer']->id)->where('type', 'project.update_published')->count());
            DB::commit();
            $completed = $this->finish($peer);
            self::assertSame($readerBackend, $completed['backend']);
            self::assertSame(200, $completed['status']);
            self::assertSame(1, $completed['identity_reads'], 'A deadlock retry must not hide an identity/resource lock inversion.');
            self::assertSame(1, DB::table('project_updates')->where('content', 'Concurrent customer-safe update.')->count());
        } finally {
            $this->cleanup($peer);
        }
    }

    #[DataProvider('authorityMutations')]
    public function test_authority_mutation_still_waits_until_the_authorized_read_commits(string $mode, string $mutation): void
    {
        $fixture = $this->projectFixture();
        $this->signIn($fixture['customer'])->assertOk();
        $reader = $writer = null;
        DB::beginTransaction();
        try {
            DB::table('projects')->where('id', $fixture['project']->id)->lockForUpdate()->firstOrFail();
            $parent = DB::scalar('SELECT pg_backend_pid()');
            [$reader, $application] = $this->startWorker($fixture, $mode);
            $readerBackend = $this->waitForLock($reader, $application, 'projects', $parent);
            [$writer, $mutationApplication] = $this->startWorker($fixture, $mutation);
            $writerBackend = $this->waitForLock($writer, $mutationApplication, 'users', $readerBackend);
            self::assertNotSame($writerBackend, $parent);
            self::assertSame(1, DB::table('users')->where('id', $fixture['customer']->id)->value('auth_version'));
            self::assertTrue(DB::table('users')->where('id', $fixture['customer']->id)->value('enabled'));
            self::assertSame(1, DB::table('user_roles')->where('user_id', $fixture['customer']->id)->count());
            self::assertSame(1, DB::table('identity_sessions')->where('user_id', $fixture['customer']->id)->count());
            DB::commit();
            self::assertSame(200, $this->finish($reader)['status']);
            self::assertSame(200, $this->finish($writer)['status']);
            self::assertSame(2, DB::table('users')->where('id', $fixture['customer']->id)->value('auth_version'));
            self::assertSame(0, DB::table('identity_sessions')->where('user_id', $fixture['customer']->id)->count());
            $this->browser('GET', '/api/v1/projects/'.$fixture['project']->id)->assertUnauthorized();
            if ($mutation === 'disable') {
                try {
                    DB::transaction(fn () => app(ReadActiveIdentity::class)->locked($fixture['customer']->id));
                    self::fail('Disabled identity retained durable authority.');
                } catch (AuthorizationException) {
                    self::assertTrue(true);
                }
            } elseif ($mutation === 'role_revoke') {
                self::assertSame([], DB::transaction(fn () => app(ReadActiveIdentity::class)->locked($fixture['customer']->id))->permissions);
            }
        } finally {
            $this->cleanup($reader, $writer);
        }
    }

    public static function readers(): array
    {
        return [['http'], ['durable']];
    }

    public static function authorityMutations(): array
    {
        return [['http', 'disable'], ['durable', 'disable'], ['http', 'role_revoke'], ['durable', 'role_revoke'], ['http', 'session_revoke'], ['durable', 'session_revoke']];
    }

    private function startWorker(array $fixture, string $mode): array
    {
        $application = 'notification-identity-'.Str::uuid7();
        $payload = base64_encode(json_encode(['application' => $application, 'mode' => $mode, 'user' => $fixture['customer']->id,
            'customer' => $fixture['project']->customer_id, 'project' => $fixture['project']->id, 'cookies' => $this->browserCookies], JSON_THROW_ON_ERROR));
        $peer = new Process([PHP_BINARY, base_path('tests/Fixtures/notification-identity-worker.php'), $payload], base_path(), timeout: 20);
        $peer->start();

        return [$peer, $application];
    }

    private function waitForLock(Process $peer, string $application, string $table, int $blocker): int
    {
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $activity = DB::table('pg_stat_activity')->where('application_name', $application)->where('wait_event_type', 'Lock')
                ->where('query', 'like', '%"'.$table.'"%')->whereRaw('? = ANY(pg_blocking_pids(pid))', [$blocker])->first();
            if ($activity !== null) {
                self::assertNotSame($blocker, $activity->pid);

                return $activity->pid;
            }
            if (! $peer->isRunning()) {
                self::fail('The independent operation did not wait at the intended row: '.$peer->getErrorOutput().$peer->getOutput());
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        self::fail('Expected a real PostgreSQL row-lock wait on '.$table.'.');
    }

    private function finish(Process $peer): array
    {
        self::assertSame(0, $peer->wait(), $peer->getErrorOutput().$peer->getOutput());

        return json_decode($peer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function cleanup(?Process ...$peers): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($peers as $peer) {
            if ($peer?->isRunning()) {
                $peer->stop(0);
            }
        }
    }
}
