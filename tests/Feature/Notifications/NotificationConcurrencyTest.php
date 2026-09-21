<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class NotificationConcurrencyTest extends TestCase
{
    use CommercialDatabase;
    use IntakeFixtures;

    public function test_parallel_identical_business_events_create_one_logical_notice_and_delivery_operation(): void
    {
        $user = $this->intakeCustomer();
        DB::table('notification_inboxes')->insert(['user_id' => $user->id]);
        $application = 'notification-race-'.Str::uuid7();
        $input = ['recipient' => $user->id, 'resource' => (string) Str::uuid7(), 'key' => (string) Str::uuid7()];
        $peers = [];
        DB::beginTransaction();
        try {
            DB::table('notification_inboxes')->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $parent = DB::scalar('SELECT pg_backend_pid()');
            foreach ([1, 2] as $index) {
                $payload = base64_encode(json_encode([...$input, 'application' => $application.'-'.$index], JSON_THROW_ON_ERROR));
                $peer = new Process([PHP_BINARY, base_path('tests/Fixtures/notification-record-worker.php'), $payload], base_path(), timeout: 20);
                $peer->start();
                $peers[] = $peer;
            }
            $deadline = microtime(true) + 8;
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
            self::assertSame(2, $waiting, 'Both PostgreSQL workers must wait on the recipient inbox.');
            DB::commit();
            $results = [];
            foreach ($peers as $peer) {
                self::assertSame(0, $peer->wait(), $peer->getErrorOutput().$peer->getOutput());
                $result = json_decode($peer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertNotSame($parent, $result['backend']);
                $results[] = $result;
            }
            self::assertNotSame($results[0]['backend'], $results[1]['backend']);
            self::assertSame($results[0]['id'], $results[1]['id']);
            $this->assertDatabaseCount('notifications', 1);
            $this->assertDatabaseCount('notification_deliveries', 1);
            self::assertSame(1, DB::table('async_operations')->where('kind', 'notifications.email')->count());
            self::assertSame(2, DB::table('notification_inboxes')->where('user_id', $user->id)->value('lock_version'));
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
