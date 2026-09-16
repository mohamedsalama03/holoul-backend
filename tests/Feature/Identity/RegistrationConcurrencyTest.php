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
            $this->assertDatabaseCount('identity_recovery_mail', 1);
            $this->assertDatabaseCount('identity_recovery_tokens', 1);
            $this->assertDatabaseCount('async_operations', 1);
            $this->assertDatabaseHas('identity_recovery_tokens', ['user_id' => $user->id, 'purpose' => 'verify_email', 'consumed_at' => null, 'revoked_at' => null]);
            $mail = DB::table('identity_recovery_mail')->where('user_id', $user->id)->sole();
            self::assertSame('pending', $mail->state);
            self::assertNotNull($mail->encrypted_payload);
            $this->assertDatabaseHas('async_operations', ['id' => $mail->operation_id, 'kind' => 'identity.recovery_mail', 'state' => 'pending']);
            self::assertSame(1, DB::table('audit_events')->where('event_type', 'identity.registered')->where('subject_id', $user->id)->count());
            self::assertSame(1, DB::table('audit_events')->where('event_type', 'identity.role.customer.granted')->where('subject_id', $user->id)->count());
            self::assertSame(1, DB::table('audit_events')->where('event_type', 'identity.verification.issued')->where('subject_id', $user->id)->count());
            self::assertSame(1, $queue->size($queueName));
            // No worker consumes this unique queue. The test proves durable
            // registration intent while leaving every SMTP payload unsent.
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
