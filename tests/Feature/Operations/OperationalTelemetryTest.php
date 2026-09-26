<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Application\Operations\ObservedAIProvider;
use App\Application\Operations\ObservedEmailProvider;
use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Infrastructure\Async\OperationHandlerRegistry;
use App\Infrastructure\Async\OperationRecorder;
use App\Infrastructure\Async\OperationRunner;
use App\Infrastructure\Operations\HttpTelemetry;
use App\Infrastructure\Operations\MetricRecorder;
use App\Infrastructure\Operations\OperationalSnapshot;
use App\Infrastructure\Operations\ProcessTelemetry;
use App\Modules\AI\Contracts\AIProvider;
use App\Modules\AI\Data\AIInput;
use App\Modules\AI\Data\AIResult;
use App\Modules\Notifications\Contracts\EmailProvider;
use App\Modules\Notifications\Contracts\NotificationRecorder;
use App\Modules\Notifications\Data\EmailMessage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CommercialDatabase;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class OperationalTelemetryTest extends TestCase
{
    use CommercialDatabase;
    use IntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('operations.deployment_profile', 'local-verification');
        Config::set('database.redis.options.prefix', 'holoul_testing_operations_'.Str::uuid7().'_');
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        Queue::fake();
    }

    public function test_http_measurement_uses_final_error_status_and_closed_dimensions_without_resource_ids_or_query_content(): void
    {
        $secret = 'private-password-and-document';
        $id = (string) Str::uuid7();
        $request = Request::create('https://localhost:8443/api/v1/projects/'.$id.'?token='.$secret);
        $middleware = app(HttpTelemetry::class);
        $response = $middleware->handle($request, fn () => new Response('', 403));
        $middleware->terminate($request, $response);
        $metrics = app(MetricRecorder::class)->snapshot();
        self::assertTrue($metrics['available']);
        self::assertSame(1, $metrics['counters']['http.projects.GET.4xx.count']);
        $encoded = json_encode($metrics, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($id, $encoded);
        self::assertStringNotContainsString($secret, $encoded);
        app(MetricRecorder::class)->http($secret, $secret, 500, 10, 0, 0);
        self::assertArrayHasKey('http.other.OTHER.5xx.count', app(MetricRecorder::class)->snapshot()['counters']);
    }

    public function test_storage_failures_are_measured_without_hiding_the_original_exception_or_using_private_labels(): void
    {
        $failure = new \RuntimeException('private/object/version secret');
        try {
            app(MetricRecorder::class)->storage('open', fn () => throw $failure);
            self::fail('Storage error was hidden.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $snapshot = app(MetricRecorder::class)->snapshot();
        self::assertSame(1, $snapshot['counters']['storage.open.failure.count']);
        self::assertStringNotContainsString('private', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function test_metrics_are_disposable_and_redis_failure_does_not_break_readiness_or_core_http(): void
    {
        Config::set('database.redis.cache.port', '1');
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        app(MetricRecorder::class)->http('intake', 'GET', 200, 10, 1, 2);
        self::assertFalse(app(MetricRecorder::class)->snapshot()['available']);
        $this->getJson('/health/live')->assertOk();
        $this->getJson('/health/ready')->assertOk();
        $this->getJson('/api/v1')->assertOk();
    }

    public function test_readiness_rejects_unsafe_security_configuration_but_ignores_optional_ai_and_storage_outages(): void
    {
        Config::set('ai.enabled', false);
        Config::set('documents.scanner_socket', '/missing/scanner.sock');
        Config::set('documents.inspector_socket', '/missing/inspector.sock');
        $this->getJson('/health/ready')->assertOk();
        Config::set('session.secure', false);
        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
        $this->getJson('/health/live')->assertOk();
    }

    public function test_snapshot_separates_queue_backlogs_provider_attempts_and_unknown_backup_status_without_pii(): void
    {
        $customer = $this->intakeCustomer();
        $id = DB::transaction(fn () => app(NotificationRecorder::class)->record($customer->id, 'project.created', 'project', (string) Str::uuid7(), 'telemetry', (string) Str::uuid7()));
        app(OperationRecorder::class)->record('ai.generate', 'telemetry-ai', ['ai_run_id' => (string) Str::uuid7()]);
        app(OperationRecorder::class)->record('documents.scan', 'telemetry-scan', ['document_id' => (string) Str::uuid7()]);
        app(ProcessTelemetry::class)->heartbeat('notifications', 'started');
        app(ProcessTelemetry::class)->heartbeat('notifications', 'finished');
        Config::set('operations.backup_status_file', '/missing/backup-status.json');
        Config::set('operations.restore_status_file', '/missing/restore-status.json');
        $snapshot = app(OperationalSnapshot::class)->collect();
        self::assertTrue($snapshot['postgres']['available']);
        self::assertTrue($snapshot['redis']['available']);
        foreach (['notifications', 'ai', 'documents'] as $queue) {
            self::assertSame(1, $snapshot['postgres']['durable_backlog'][$queue]['pending']);
        }
        self::assertSame(1, $snapshot['redis']['processes']['notifications'][0]['finished_jobs']);
        self::assertSame('unknown', $snapshot['backup']['state']);
        self::assertSame('unknown', $snapshot['restore']['state']);
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
        foreach ([$customer->email, $customer->id, $id, Config::string('database.connections.pgsql.password'), 'private/object'] as $private) {
            self::assertStringNotContainsString($private, $encoded);
        }
    }

    public function test_durable_operation_measurement_counts_first_claim_wait_and_execution_once_without_duplicate_delivery(): void
    {
        app(OperationHandlerRegistry::class)->register('operations.test', new class implements OperationHandler
        {
            public function execute(OperationClaim $operation): Closure
            {
                return static function (): void {};
            }
        });
        $operation = app(OperationRecorder::class)->record('operations.test', 'telemetry-run');
        app(OperationRunner::class)->run($operation->id);
        app(OperationRunner::class)->run($operation->id);
        $metrics = app(MetricRecorder::class)->snapshot()['counters'];
        self::assertSame(1, $metrics['operation.default.queue_wait.count']);
        self::assertSame(1, $metrics['operation.default.execution.count']);
        $this->assertDatabaseHas('async_operations', ['id' => $operation->id, 'state' => 'succeeded']);
        DB::table('async_operations')->where('id', $operation->id)->update(['completed_at' => DB::raw("created_at - interval '500 milliseconds'")]);
        $projection = app(OperationalSnapshot::class)->collect()['postgres'];
        self::assertTrue($projection['available']);
        self::assertSame(1000, $projection['timestamp_precision_ms']);
        self::assertSame(1, $projection['recent_durable']['default']['negative_interval_count']);
        self::assertEquals(0, $projection['recent_durable']['default']['completion_p95_ms']);
    }

    public function test_provider_monotonic_measurement_preserves_outcomes_and_excludes_private_content(): void
    {
        self::assertInstanceOf(ObservedAIProvider::class, app(AIProvider::class));
        self::assertInstanceOf(ObservedEmailProvider::class, app(EmailProvider::class));
        $result = new AIResult('{"private":"output"}', 1, 2, 3);
        $provider = $this->createMock(AIProvider::class);
        $provider->expects(self::once())->method('generate')->willReturn($result);
        $observed = new ObservedAIProvider($provider, app(MetricRecorder::class));
        self::assertSame($result, $observed->generate(new AIInput((string) Str::uuid7(), 'classification', 'sandbox-v1', 'private-input', [], 1, 2, 100)));
        $failure = new \RuntimeException('private-email-provider-message');
        $mailer = $this->createMock(EmailProvider::class);
        $mailer->expects(self::once())->method('send')->willThrowException($failure);
        try {
            (new ObservedEmailProvider($mailer, app(MetricRecorder::class)))->send(new EmailMessage('private@example.test', 'private-title', 'private-body', 'private-id'));
            self::fail('Provider exception was hidden.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $snapshot = app(MetricRecorder::class)->snapshot();
        self::assertSame(1, $snapshot['counters']['provider.ai.success.count']);
        self::assertSame(1, $snapshot['counters']['provider.notifications.failure.count']);
        self::assertGreaterThanOrEqual(0, $snapshot['counters']['provider.ai.success.duration_ms']);
        self::assertStringNotContainsString('private', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
