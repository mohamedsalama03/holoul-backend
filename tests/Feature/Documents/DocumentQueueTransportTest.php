<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Infrastructure\Async\OperationPolicy;
use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Async\RunDocumentOperationJob;
use App\Infrastructure\Async\RunOperationJob;
use App\Infrastructure\Queue\SafeFailedJobProvider;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class DocumentQueueTransportTest extends TestCase
{
    use DatabaseMigrations;

    public function test_documents_policy_routes_after_commit_without_changing_inherited_defaults(): void
    {
        Queue::fake();
        DB::beginTransaction();
        $operation = app(OperationRecorder::class)->record('documents.scan', 'document-policy', ['document_id' => (string) Str::uuid7()]);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushedOn('documents', RunDocumentOperationJob::class);
        Queue::assertNotPushed(RunOperationJob::class);
        self::assertSame(3, $operation->max_attempts);
        self::assertSame(150, OperationPolicy::leaseSeconds('documents.scan'));
        self::assertSame(120, (new RunDocumentOperationJob($operation->id))->timeout);
        self::assertSame(180, Config::integer('queue.connections.documents.retry_after'));
        self::assertSame(30, OperationPolicy::backoffSeconds('documents.scan', 1));
        self::assertSame(120, OperationPolicy::backoffSeconds('documents.scan', 2));
        self::assertSame(60, OperationPolicy::leaseSeconds('foundation.test'));
        self::assertSame(5, OperationPolicy::maxAttempts('foundation.test'));
    }

    public function test_documents_failed_payload_is_identifier_only_and_replays_through_real_redis(): void
    {
        self::assertStringStartsWith('holoul_testing_', Config::string('database.redis.options.prefix'));
        $connection = Queue::connection('documents');
        self::assertInstanceOf(RedisQueue::class, $connection);
        $connection->clear('documents');
        $id = (string) Str::uuid7();
        $connection->push(new RunDocumentOperationJob($id), '', 'documents');
        $job = $connection->pop('documents');
        self::assertNotNull($job);
        try {
            $provider = app('queue.failer');
            self::assertInstanceOf(SafeFailedJobProvider::class, $provider);
            $uuid = $provider->log('documents', 'documents', $job->getRawBody(), new RuntimeException('secret document /private/object'));
            self::assertNotNull($uuid);
            $job->delete();
            $stored = DB::table('failed_jobs')->where('uuid', $uuid)->first();
            self::assertNotNull($stored);
            self::assertSame('documents', $stored->connection);
            self::assertStringNotContainsString('secret', json_encode($stored, JSON_THROW_ON_ERROR));
            self::assertSame(serialize(new RunDocumentOperationJob($id)), json_decode($stored->payload, true, flags: JSON_THROW_ON_ERROR)['data']['command']);
            $this->artisan('queue:retry', ['id' => [$uuid]])->assertExitCode(0);
            $retry = $connection->pop('documents');
            self::assertNotNull($retry);
            self::assertSame(serialize(new RunDocumentOperationJob($id)), $retry->payload()['data']['command']);
            $retry->delete();
        } finally {
            $job->delete();
            $connection->clear('documents');
        }
    }
}
