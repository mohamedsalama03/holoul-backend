<?php

declare(strict_types=1);

namespace Tests\Feature\PublicServices;

use App\Modules\Contact\Actions\ReceiveContact;
use App\Modules\Contact\Data\ContactSubmission;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\TestCase;

final class ContactConcurrencyTest extends TestCase
{
    use CommercialDatabase;

    #[DataProvider('races')]
    public function test_independent_postgresql_requests_serialize_one_receipt_and_one_intent(bool $different, bool $expired): void
    {
        Queue::fake();
        $key = (string) Str::uuid7();
        if ($expired) {
            app(ReceiveContact::class)->handle(new ContactSubmission('Concurrent Contact', 'concurrent@example.test', '+12025550123', null, 'Original expired message'), $key, (string) Str::uuid7());
            DB::table('contact_submission_keys')->update(['expires_at' => DB::raw('clock_timestamp()')]);
        }
        $hash = hash_hmac('sha256', 'contact.submit:'.$key, Config::string('app.key'));
        $lock = 'contact.submit:'.$hash;
        $name = 'contact-race-'.Str::uuid7();
        DB::select('SELECT pg_advisory_lock(hashtextextended(?,0))', [$lock]);
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/contact-concurrency-worker.php')], base_path(), timeout: 20);
                $worker->setInput(json_encode(['key' => $key, 'name' => $name.'-'.$i, 'message' => $different && $i === 1 ? 'A conflicting concurrent message' : 'A matching concurrent message'], JSON_THROW_ON_ERROR));
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'like', $name.'%')->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both PostgreSQL backends must compete for the idempotency lock.');
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$lock]);
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            self::assertSame($different ? [201, 409] : [201, 201], $statuses);
            self::assertNotSame($results[0]['pid'], $results[1]['pid']);
            if (! $different) {
                self::assertSame($results[0]['receipt'], $results[1]['receipt']);
            }
            $this->assertDatabaseCount('contact_messages', $expired ? 2 : 1);
            $this->assertDatabaseCount('contact_deliveries', $expired ? 2 : 1);
            $this->assertDatabaseCount('async_operations', $expired ? 2 : 1);
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?,0))', [$lock]);
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
        }
    }

    public static function races(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }
}
