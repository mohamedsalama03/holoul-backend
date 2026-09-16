<?php

declare(strict_types=1);

namespace Tests\Feature\Async;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\LostOperationLease;
use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\OperationHandlerRegistry;
use App\Infrastructure\Async\OperationPublisher;
use App\Infrastructure\Async\OperationReconciler;
use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Async\OperationRunner;
use App\Infrastructure\Async\OperationState;
use App\Infrastructure\Async\PermanentOperationFailure;
use App\Infrastructure\Async\RunOperationJob;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class DurableOperationsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('async_test_results', function (Blueprint $table): void {
            $table->uuid('operation_id')->primary();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('async_test_results');

        parent::tearDown();
    }

    public function test_only_the_outer_commit_publishes_the_operation_identifier(): void
    {
        Queue::fake();
        DB::beginTransaction();

        $operation = $this->record('committed');

        $this->assertSame(1, DB::transactionLevel());
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('async_operations', ['id' => $operation->id]);

        DB::commit();

        Queue::assertPushed(RunOperationJob::class, fn (RunOperationJob $job): bool => $job->operationId === $operation->id);
        $this->assertNotNull($operation->refresh()->last_dispatched_at);
        $this->assertTrue(Str::isUuid($operation->id, 7));
    }

    public function test_outer_rollback_removes_intent_and_after_commit_callback(): void
    {
        Queue::fake();
        DB::beginTransaction();
        $this->record('rollback');
        DB::rollBack();

        $this->assertDatabaseCount('async_operations', 0);
        Queue::assertNothingPushed();
    }

    public function test_database_preserves_subsecond_deadlines_without_rounding_work_into_the_future(): void
    {
        Queue::fake();
        $operation = $this->record('subsecond-deadline');
        $deadline = '2026-09-16T00:00:00.750000+00:00';
        AsyncOperation::query()->whereKey($operation->id)->update(['next_attempt_at' => $deadline]);

        $this->assertTrue(DB::scalar(
            'SELECT next_attempt_at = ?::timestamptz FROM async_operations WHERE id = ?',
            [$deadline, $operation->id],
        ));
    }

    public function test_duplicate_identity_and_delivery_commit_one_logical_result(): void
    {
        Queue::fake();
        $this->registerResultHandler();
        $first = $this->record('duplicate');
        $second = $this->record('duplicate');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('async_operations', 1);
        Queue::assertPushed(RunOperationJob::class, 2);

        $runner = app(OperationRunner::class);
        $runner->run($first->id);
        $runner->run($second->id);

        $this->assertDatabaseCount('async_test_results', 1);
        $this->assertSame(OperationState::Succeeded, $first->refresh()->state);
        $this->assertSame(1, $first->attempts);
    }

    public function test_same_identity_with_changed_references_is_rejected(): void
    {
        Queue::fake();
        app(OperationRecorder::class)->record('test.result', 'same-key', ['revision_id' => (string) Str::uuid7()]);

        $this->expectException(LogicException::class);
        app(OperationRecorder::class)->record('test.result', 'same-key', ['revision_id' => (string) Str::uuid7()]);
    }

    public function test_redis_receives_identifiers_and_real_duplicate_deliveries_are_safe(): void
    {
        $queueName = 'async-test-'.Str::uuid7();
        Config::set('async.queue', $queueName);
        $this->registerResultHandler();
        $reference = (string) Str::uuid7();
        $operation = app(OperationRecorder::class)->record('test.result', 'real-transport', ['revision_id' => $reference]);
        app(OperationPublisher::class)->publish($operation->id);
        $queue = Queue::connection('redis');

        $this->assertSame(2, $queue->size($queueName));

        for ($delivery = 0; $delivery < 2; $delivery++) {
            $job = $queue->pop($queueName);
            $this->assertNotNull($job);
            $payload = $job->getRawBody();
            $this->assertStringContainsString($operation->id, $payload);
            $this->assertStringNotContainsString($reference, $payload);
            $this->assertStringNotContainsString('real-transport', $payload);

            try {
                $job->fire();
            } finally {
                $job->delete();
            }
        }

        $this->assertSame(0, $queue->size($queueName));
        $this->assertDatabaseCount('async_test_results', 1);
        $this->assertSame(OperationState::Succeeded, $operation->refresh()->state);
    }

    public function test_transport_failure_does_not_reverse_a_committed_intent(): void
    {
        Queue::shouldReceive('connection')->once()->with('redis')->andThrow(new RuntimeException('Sensitive transport detail'));

        $operation = $this->record('transport-down');

        $this->assertDatabaseHas('async_operations', ['id' => $operation->id, 'state' => 'pending']);
        $this->assertNull($operation->refresh()->last_dispatched_at);
    }

    public function test_reconciler_recovers_an_actually_lost_redis_message(): void
    {
        $queueName = 'async-test-'.Str::uuid7();
        Config::set('async.queue', $queueName);
        $this->registerResultHandler();
        $operation = $this->record('redis-message-lost');
        $queue = Queue::connection('redis');
        $lostJob = $queue->pop($queueName);
        $this->assertNotNull($lostJob);
        $lostJob->delete();
        $this->assertSame(0, $queue->size($queueName));
        $this->assertDatabaseCount('async_test_results', 0);

        AsyncOperation::query()->whereKey($operation->id)->update(['last_dispatched_at' => DB::raw("clock_timestamp() - interval '5 minutes'")]);
        $this->assertSame(1, app(OperationReconciler::class)->reconcile());
        $recoveredJob = $queue->pop($queueName);
        $this->assertNotNull($recoveredJob);

        try {
            $recoveredJob->fire();
        } finally {
            $recoveredJob->delete();
        }

        $this->assertDatabaseCount('async_test_results', 1);
        $this->assertSame(OperationState::Succeeded, $operation->refresh()->state);
    }

    public function test_reconciliation_finds_unpublished_lost_enqueued_and_expired_running_work(): void
    {
        Queue::fake();
        $unpublished = $this->record('unpublished');
        $lost = $this->record('lost');
        $expired = $this->record('expired');
        $fresh = $this->record('recently-enqueued');
        $deferred = $this->record('deferred');

        AsyncOperation::query()->whereKey($unpublished->id)->update(['last_dispatched_at' => null]);
        AsyncOperation::query()->whereKey($lost->id)->update(['last_dispatched_at' => DB::raw("clock_timestamp() - interval '5 minutes'")]);
        $this->assertNotNull(app(OperationRunner::class)->claim($expired->id));
        AsyncOperation::query()->whereKey($expired->id)->update([
            'lease_expires_at' => DB::raw("clock_timestamp() - interval '1 minute'"),
            'last_dispatched_at' => DB::raw("clock_timestamp() - interval '5 minutes'"),
        ]);
        AsyncOperation::query()->whereKey($deferred->id)->update([
            'last_dispatched_at' => null,
            'next_attempt_at' => DB::raw("clock_timestamp() + interval '5 minutes'"),
        ]);

        Queue::fake();
        $reconciler = app(OperationReconciler::class);
        $this->assertSame(2, $reconciler->reconcile(2));
        $this->assertSame(1, $reconciler->reconcile(2));
        $this->assertSame(0, $reconciler->reconcile(2));
        Queue::assertPushed(RunOperationJob::class, 3);
        Queue::assertNotPushed(RunOperationJob::class, fn (RunOperationJob $job): bool => in_array($job->operationId, [$fresh->id, $deferred->id], true));
    }

    public function test_an_active_lease_cannot_be_claimed_and_an_expired_fence_cannot_commit(): void
    {
        Queue::fake();
        $operation = $this->record('fencing');
        $runner = app(OperationRunner::class);
        $first = $runner->claim($operation->id);
        $this->assertNotNull($first);
        $this->assertNull($runner->claim($operation->id));

        AsyncOperation::query()->whereKey($operation->id)->update(['lease_expires_at' => DB::raw("clock_timestamp() - interval '1 minute'")]);
        $second = $runner->claim($operation->id);
        $this->assertNotNull($second);
        $this->assertGreaterThan($first->fence, $second->fence);

        try {
            $runner->complete($first, function () use ($operation): void {
                DB::table('async_test_results')->insert(['operation_id' => $operation->id]);
            });
            $this->fail('A stale fence was allowed to commit.');
        } catch (LostOperationLease) {
            $this->assertDatabaseCount('async_test_results', 0);
        }

        $runner->complete($second, function () use ($operation): void {
            DB::table('async_test_results')->insert(['operation_id' => $operation->id]);
        });
        $this->assertDatabaseCount('async_test_results', 1);
        $this->assertSame(OperationState::Succeeded, $operation->refresh()->state);
    }

    public function test_postgresql_row_lock_serializes_independent_connection_claims(): void
    {
        Queue::fake();
        $operation = $this->record('concurrent-claim');
        $primary = DB::getDefaultConnection();
        Config::set('database.connections.async_peer', Config::array('database.connections.'.$primary));
        $peer = DB::connection('async_peer');
        $peer->statement("SET lock_timeout = '100ms'");

        DB::beginTransaction();

        try {
            $this->assertNotNull(app(OperationRunner::class)->claim($operation->id));
            DB::setDefaultConnection('async_peer');

            try {
                app(OperationRunner::class)->claim($operation->id);
                $this->fail('An independent claim bypassed the PostgreSQL row lock.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            } finally {
                DB::setDefaultConnection($primary);
            }

            DB::commit();
            DB::setDefaultConnection('async_peer');
            $this->assertNull(app(OperationRunner::class)->claim($operation->id));
        } finally {
            DB::setDefaultConnection($primary);

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            DB::purge('async_peer');
        }
    }

    public function test_result_writes_roll_back_with_failed_success_and_retry_is_deferred(): void
    {
        Queue::fake();
        app(OperationHandlerRegistry::class)->register('test.result', new class implements OperationHandler
        {
            public function execute(OperationClaim $operation): Closure
            {
                return function () use ($operation): void {
                    DB::table('async_test_results')->insert(['operation_id' => $operation->id]);

                    throw new RuntimeException('Secret provider body must not be persisted.');
                };
            }
        });
        $operation = $this->record('failed-writer');
        $runner = app(OperationRunner::class);
        $runner->run($operation->id);

        $this->assertDatabaseCount('async_test_results', 0);
        $this->assertSame(OperationState::Pending, $operation->refresh()->state);
        $this->assertSame('execution_failed', $operation->failure_code);
        $this->assertNull($operation->completed_at);
        $this->assertNull($runner->claim($operation->id));
        $this->assertSame(1, $operation->attempts);
    }

    public function test_unregistered_handlers_fail_without_automatic_retry(): void
    {
        Queue::fake();
        $operation = $this->record('unknown-handler');
        app(OperationRunner::class)->run($operation->id);

        $this->assertSame(OperationState::Failed, $operation->refresh()->state);
        $this->assertSame('handler_missing', $operation->failure_code);
        $this->assertNotNull($operation->completed_at);
        $this->assertNull(app(OperationRunner::class)->claim($operation->id));
    }

    public function test_a_retry_can_succeed_after_its_database_eligibility_time(): void
    {
        Queue::fake();
        app(OperationHandlerRegistry::class)->register('test.result', new class implements OperationHandler
        {
            public function execute(OperationClaim $operation): Closure
            {
                if ($operation->attempt === 1) {
                    throw new RuntimeException('Transient error.');
                }

                return function () use ($operation): void {
                    DB::table('async_test_results')->insert(['operation_id' => $operation->id]);
                };
            }
        });
        $operation = $this->record('retry-success');
        $runner = app(OperationRunner::class);
        $runner->run($operation->id);
        $this->assertSame(OperationState::Pending, $operation->refresh()->state);
        AsyncOperation::query()->whereKey($operation->id)->update(['next_attempt_at' => DB::raw("clock_timestamp() - interval '1 second'")]);
        $runner->run($operation->id);

        $this->assertSame(OperationState::Succeeded, $operation->refresh()->state);
        $this->assertSame(2, $operation->attempts);
        $this->assertNull($operation->failure_code);
        $this->assertDatabaseCount('async_test_results', 1);
    }

    public function test_ambiguous_external_outcome_is_not_automatically_retried(): void
    {
        Queue::fake();
        app(OperationHandlerRegistry::class)->register('test.result', new class implements OperationHandler
        {
            public function execute(OperationClaim $operation): Closure
            {
                throw new PermanentOperationFailure('external_result_unknown');
            }
        });
        $operation = $this->record('ambiguous');
        app(OperationRunner::class)->run($operation->id);

        $this->assertSame(OperationState::Failed, $operation->refresh()->state);
        $this->assertSame('external_result_unknown', $operation->failure_code);
    }

    public function test_expired_final_attempt_is_terminal_instead_of_reclaimed_forever(): void
    {
        Queue::fake();
        $operation = $this->record('exhausted');
        $runner = app(OperationRunner::class);
        $this->assertNotNull($runner->claim($operation->id));
        AsyncOperation::query()->whereKey($operation->id)->update([
            'attempts' => 5,
            'lease_expires_at' => DB::raw("clock_timestamp() - interval '1 minute'"),
        ]);

        $this->assertNull($runner->claim($operation->id));
        $this->assertSame(OperationState::Failed, $operation->refresh()->state);
        $this->assertSame('attempts_exhausted', $operation->failure_code);
    }

    public function test_result_transaction_that_outlives_lease_is_rolled_back(): void
    {
        Queue::fake();
        $operation = $this->record('lease-during-commit');
        $runner = app(OperationRunner::class);
        $claim = $runner->claim($operation->id);
        $this->assertNotNull($claim);

        try {
            $runner->complete($claim, function () use ($operation): void {
                DB::table('async_test_results')->insert(['operation_id' => $operation->id]);
                AsyncOperation::query()->whereKey($operation->id)->update(['lease_expires_at' => DB::raw("clock_timestamp() - interval '1 second'")]);
            });
            $this->fail('An expired result transaction was allowed to commit.');
        } catch (LostOperationLease) {
            $this->assertDatabaseCount('async_test_results', 0);
            $this->assertSame(OperationState::Running, $operation->refresh()->state);
        }
    }

    private function record(string $key): AsyncOperation
    {
        return app(OperationRecorder::class)->record('test.result', $key);
    }

    private function registerResultHandler(): void
    {
        app(OperationHandlerRegistry::class)->register('test.result', new class implements OperationHandler
        {
            public function execute(OperationClaim $operation): Closure
            {
                return function () use ($operation): void {
                    DB::table('async_test_results')->insert(['operation_id' => $operation->id]);
                };
            }
        });
    }
}
