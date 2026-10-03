<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RegistrationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_independent_concurrent_registration_creates_one_complete_customer_and_both_calls_succeed(): void
    {
        $application = 'registration-proof-'.Str::uuid7();
        $queueName = 'registration-proof-'.Str::uuid7();
        $gate = random_int(1, 2_000_000_000);
        $email = 'concurrent-registration@example.test';
        $peers = [];
        $gateHeld = true;
        $queue = Queue::connection('redis');
        self::assertInstanceOf(RedisQueue::class, $queue);
        DB::select('SELECT pg_advisory_lock(?::bigint)', [$gate]);

        try {
            for ($index = 0; $index < 2; $index++) {
                $peer = new Process([
                    PHP_BINARY, base_path('tests/Feature/Identity/RegistrationFixtures/register.php'),
                    (string) $gate, $application.'-'.$index, $queueName, $email,
                ], base_path(), timeout: 5);
                $peer->start();
                $peers[] = $peer;
            }

            $deadline = microtime(true) + 3;
            $waiting = 0;
            do {
                foreach ($peers as $peer) {
                    $peer->checkTimeout();
                    if (! $peer->isRunning()) {
                        self::fail('A registration peer exited before reaching the barrier: '.$peer->getErrorOutput());
                    }
                }
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')
                    ->where('application_name', 'like', $application.'%')
                    ->where('wait_event_type', 'Lock')->where('wait_event', 'advisory')->count();
                if ($waiting === 2) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);

            self::assertSame(2, $waiting, 'Both independent PostgreSQL processes must reach the shared advisory gate.');
            self::assertSame(0, DB::table('users')->where('email', $email)->count());
            // Releasing an exclusive gate admits both shared waiters together;
            // there is deliberately no existing identity row to lock here.
            DB::select('SELECT pg_advisory_unlock(?::bigint)', [$gate]);
            $gateHeld = false;

            foreach ($peers as $peer) {
                self::assertSame(0, $peer->wait(), $peer->getErrorOutput());
                self::assertSame('accepted', trim($peer->getOutput()));
            }

            $user = DB::table('users')->where('email', $email)->sole();
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('customers', 1);
            $this->assertDatabaseHas('customers', ['user_id' => $user->id, 'customer_kind' => 'customer']);
            $this->assertDatabaseCount('user_roles', 1);
            self::assertSame(1, DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', $user->id)->where('user_roles.user_kind', 'customer')
                ->where('roles.code', 'customer')->count());
            $this->assertDatabaseCount('identity_recovery_mail', 0);
            $this->assertDatabaseCount('identity_recovery_tokens', 0);
            $this->assertDatabaseCount('async_operations', 0);
            self::assertNull($user->email_verified_at);
            self::assertSame(1, DB::table('audit_events')->where('event_type', 'identity.registered')->where('subject_id', $user->id)->count());
            self::assertSame(1, DB::table('audit_events')->where('event_type', 'identity.role.customer.granted')->where('subject_id', $user->id)->count());
            self::assertSame(0, DB::table('audit_events')->where('event_type', 'identity.verification.issued')->where('subject_id', $user->id)->count());
            self::assertSame(0, $queue->size($queueName));
            // Concurrent registration commits one identity/profile/role/audit,
            // with no verification token, mail intent or queue dispatch.
        } finally {
            foreach ($peers as $peer) {
                if ($peer->isRunning()) {
                    $peer->stop(0);
                }
            }
            if ($gateHeld) {
                DB::select('SELECT pg_advisory_unlock(?::bigint)', [$gate]);
            }
            $queue->clear($queueName);
        }
    }
}
