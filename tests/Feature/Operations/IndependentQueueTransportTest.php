<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Infrastructure\Async\OperationPolicy;
use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Async\RunAIOperationJob;
use App\Infrastructure\Async\RunOperationJob;
use App\Infrastructure\Queue\SafeFailedJobProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommercialDatabase;
use Tests\TestCase;

final class IndependentQueueTransportTest extends TestCase
{
    use CommercialDatabase;

    public function test_notifications_have_independent_after_commit_transport_and_safe_default_timing(): void
    {
        Queue::fake();
        DB::beginTransaction();
        app(OperationRecorder::class)->record('notifications.email', 'isolated-notice', ['delivery_id' => (string) Str::uuid7()]);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushedOn('notifications', RunOperationJob::class);
        Queue::assertPushed(RunOperationJob::class, 1);
        self::assertSame(60, OperationPolicy::leaseSeconds('notifications.email'));
        self::assertSame(90, Config::integer('queue.connections.notifications.retry_after'));
    }

    #[DataProvider('channels')]
    public function test_ai_and_notifications_failed_envelopes_remain_canonical_and_replay_on_their_real_redis_queue(string $queue): void
    {
        self::assertStringStartsWith('holoul_testing_', Config::string('database.redis.options.prefix'));
        $connection = Queue::connection($queue);
        $connection->clear($queue);
        $id = (string) Str::uuid7();
        $expected = $queue === 'ai' ? new RunAIOperationJob($id) : new RunOperationJob($id);
        $connection->push($expected, '', $queue);
        $job = $connection->pop($queue);
        self::assertNotNull($job);
        try {
            $provider = app('queue.failer');
            self::assertInstanceOf(SafeFailedJobProvider::class, $provider);
            $uuid = $provider->log($queue, $queue, $job->getRawBody(), new \RuntimeException('secret provider payload private document'));
            self::assertNotNull($uuid);
            $job->delete();
            $record = DB::table('failed_jobs')->where('uuid', $uuid)->firstOrFail();
            self::assertSame($queue, $record->connection);
            self::assertStringNotContainsString('secret', json_encode($record, JSON_THROW_ON_ERROR));
            self::assertSame(serialize($expected), json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR)['data']['command']);
            $this->artisan('queue:retry', ['id' => [$uuid]])->assertExitCode(0);
            $retry = $connection->pop($queue);
            self::assertNotNull($retry);
            self::assertSame(serialize($expected), $retry->payload()['data']['command']);
            $retry->delete();
            self::assertNull($provider->log('documents', 'documents', $record->payload, new \RuntimeException));
        } finally {
            $job->delete();
            $connection->clear($queue);
        }
    }

    public static function channels(): array
    {
        return [['ai'], ['notifications']];
    }
}
