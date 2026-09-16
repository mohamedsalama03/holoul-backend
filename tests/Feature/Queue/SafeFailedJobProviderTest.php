<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Async\RunOperationJob;
use App\Infrastructure\Queue\SafeFailedJobProvider;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class SafeFailedJobProviderTest extends TestCase
{
    use DatabaseMigrations;

    public function test_framework_uses_safe_storage_and_never_persists_exception_or_context_secrets(): void
    {
        Queue::fake();
        $requestId = Str::uuid7()->toString();
        $operation = app(OperationRecorder::class)->record('foundation.test', 'failed-job-test', requestId: $requestId);
        $payload = $this->payload($operation->id);
        $payload['context'] = ['authorization' => 'Bearer secret-token', 'document' => 'secret-document'];
        $payload['data']['provider_response'] = 'secret-provider-response';
        $exception = new class('secret-sql /private/file.php', previous: new RuntimeException('secret-token')) extends RuntimeException {};

        $provider = $this->provider();
        $uuid = $provider->log('redis', 'default', json_encode($payload, JSON_THROW_ON_ERROR), $exception);

        self::assertSame($payload['uuid'], $uuid);
        $row = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        self::assertNotNull($row);
        self::assertStringNotContainsString('secret', json_encode($row, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(__FILE__, json_encode($row, JSON_THROW_ON_ERROR));
        self::assertSame([
            'code' => 'queue_job_failed',
            'exception_class' => Throwable::class,
            'request_id' => $requestId,
        ], json_decode($row->exception, true, flags: JSON_THROW_ON_ERROR));
        $storedPayload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(serialize(new RunOperationJob($operation->id)), $storedPayload['data']['command']);
        self::assertArrayNotHasKey('context', $storedPayload);
        self::assertArrayNotHasKey('provider_response', $storedPayload['data']);
    }

    public function test_a_real_redis_payload_remains_replayable_through_the_framework_retry_command(): void
    {
        $queueName = 'failed-tests-'.Str::uuid7()->toString();
        Config::set('async.queue', $queueName);
        $operationId = Str::uuid7()->toString();
        $connection = Queue::connection('redis');
        $connection->push(new RunOperationJob($operationId), '', $queueName);
        $transportJob = $connection->pop($queueName);
        self::assertNotNull($transportJob);

        try {
            $uuid = $this->provider()->log('redis', $queueName, $transportJob->getRawBody(), new RuntimeException('secret'));
            self::assertNotNull($uuid);
        } finally {
            $transportJob->delete();
        }

        $this->artisan('queue:retry', ['id' => [$uuid]])->assertExitCode(0);

        $replayed = $connection->pop($queueName);
        self::assertNotNull($replayed);

        try {
            $payload = json_decode($replayed->getRawBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(serialize(new RunOperationJob($operationId)), $payload['data']['command']);
            self::assertStringNotContainsString('secret', $replayed->getRawBody());
        } finally {
            $replayed->delete();
        }

        self::assertNull($this->provider()->find($uuid));
    }

    #[DataProvider('unsafePayloads')]
    public function test_unsupported_or_corrupt_payloads_are_discarded_without_raw_storage(string $payload): void
    {
        Log::spy();

        self::assertNull($this->provider()->log('redis', 'default', $payload, new RuntimeException('secret')));
        $this->assertDatabaseCount('failed_jobs', 0);
        Log::shouldHaveReceived('error')->once()->with('queue.failed_payload_rejected');
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePayloads(): iterable
    {
        yield 'invalid JSON' => ['secret-document'];
        yield 'unknown job' => ['{"uuid":"01994f81-2222-7000-8000-000000000001","job":"SecretJob","data":{"command":"secret"}}'];
        yield 'non-object payload' => ['["secret"]'];
        yield 'oversized payload' => [str_repeat('secret', 11_000)];
    }

    public function test_a_modified_serialized_job_is_not_deserialized_or_retained(): void
    {
        Log::spy();
        $payload = $this->payload(Str::uuid7()->toString());
        $payload['data']['command'] .= 'secret-document';

        self::assertNull($this->provider()->log('redis', 'default', json_encode($payload, JSON_THROW_ON_ERROR), new RuntimeException));
        $this->assertDatabaseCount('failed_jobs', 0);
        Log::shouldHaveReceived('error')->once()->with('queue.failed_payload_rejected');
    }

    public function test_failed_record_listing_lookup_pruning_and_deletion_remain_available(): void
    {
        $provider = $this->provider();
        $first = $provider->log('redis', 'default', json_encode($this->payload(Str::uuid7()->toString()), JSON_THROW_ON_ERROR), new RuntimeException);
        $second = $provider->log('redis', 'default', json_encode($this->payload(Str::uuid7()->toString()), JSON_THROW_ON_ERROR), new RuntimeException);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertCount(2, $provider->all());
        self::assertEqualsCanonicalizing([$first, $second], $provider->ids());
        self::assertSame(2, $provider->count('redis', 'default'));
        self::assertSame($first, $provider->find($first)?->id);

        DB::table('failed_jobs')->where('uuid', $first)->update(['failed_at' => now('UTC')->subDays(2)]);
        self::assertSame(1, $provider->prune(now('UTC')->subDay()));
        self::assertNull($provider->find($first));
        self::assertTrue($provider->forget($second));
        self::assertFalse($provider->forget($second));
        $provider->flush();
        self::assertSame(0, $provider->count());
    }

    /** @return array{uuid: string, job: string, data: array{commandName: string, command: string}} */
    private function payload(string $operationId): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'job' => CallQueuedHandler::class.'@call',
            'data' => ['commandName' => RunOperationJob::class, 'command' => serialize(new RunOperationJob($operationId))],
        ];
    }

    private function provider(): SafeFailedJobProvider
    {
        $provider = app('queue.failer');
        self::assertInstanceOf(SafeFailedJobProvider::class, $provider);

        return $provider;
    }
}
