<?php

declare(strict_types=1);

namespace Tests\Feature\PublicServices;

use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Async\RunDocumentOperationJob;
use App\Infrastructure\Async\RunOperationJob;
use App\Infrastructure\Queue\SafeFailedJobProvider;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CommercialDatabase;
use Tests\TestCase;

final class PublicServicesTransportTest extends TestCase
{
    use CommercialDatabase;

    public function test_new_intents_are_dispatched_only_after_commit_on_the_declared_queues(): void
    {
        Queue::fake();
        DB::beginTransaction();
        app(OperationRecorder::class)->record('contact.email', 'mail-transport', ['delivery_id' => (string) Str::uuid7()]);
        app(OperationRecorder::class)->record('portfolio.process_image', 'image-transport', ['asset_id' => (string) Str::uuid7()]);
        app(OperationRecorder::class)->record('portfolio.invalidate', 'origin-transport', ['invalidation_id' => (string) Str::uuid7()]);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushedOn('notifications', RunOperationJob::class);
        Queue::assertPushedOn('documents', RunDocumentOperationJob::class);
        Queue::assertPushedOn('default', RunOperationJob::class);
        Queue::assertPushed(RunOperationJob::class, 2);
        Queue::assertPushed(RunDocumentOperationJob::class, 1);
    }

    public function test_contact_real_redis_transport_and_failed_job_replay_remain_identifier_only(): void
    {
        self::assertStringStartsWith('holoul_testing_', Config::string('database.redis.options.prefix'));
        $connection = Queue::connection('notifications');
        self::assertInstanceOf(RedisQueue::class, $connection);
        $connection->clear('notifications');
        $operation = DB::transaction(fn () => app(OperationRecorder::class)->record('contact.email', 'real-contact-transport', ['delivery_id' => (string) Str::uuid7()]));
        $job = $connection->pop('notifications');
        self::assertNotNull($job, 'Contact intent must reach the notifications transport.');
        try {
            self::assertSame(serialize(new RunOperationJob($operation->id)), $job->payload()['data']['command']);
            $provider = app('queue.failer');
            self::assertInstanceOf(SafeFailedJobProvider::class, $provider);
            $uuid = $provider->log('notifications', 'notifications', $job->getRawBody(), new RuntimeException('private email address or body'));
            self::assertNotNull($uuid);
            $job->delete();
            $stored = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
            self::assertStringNotContainsString('private email', json_encode($stored, JSON_THROW_ON_ERROR));
            $this->artisan('queue:retry', ['id' => [$uuid]])->assertSuccessful();
            $retry = $connection->pop('notifications');
            self::assertNotNull($retry);
            self::assertSame(serialize(new RunOperationJob($operation->id)), $retry->payload()['data']['command']);
            $retry->delete();
        } finally {
            $job->delete();
            $connection->clear('notifications');
        }
    }
}
