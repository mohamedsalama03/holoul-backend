<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Infrastructure\Async\OperationRunner;
use App\Infrastructure\Async\RunOperationJob;
use App\Modules\Notifications\Actions\DeliverNotification;
use App\Modules\Notifications\Actions\ReconcileNotifications;
use App\Modules\Notifications\Contracts\EmailProvider;
use App\Modules\Notifications\Contracts\NotificationRecorder;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommercialDatabase;
use Tests\Support\IntakeFixtures;
use Tests\Support\NotificationProviderDouble;
use Tests\TestCase;

final class NotificationDeliveryTest extends TestCase
{
    use CommercialDatabase;
    use IntakeFixtures;

    private NotificationProviderDouble $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->provider = new NotificationProviderDouble;
        app()->instance(EmailProvider::class, $this->provider);
    }

    public function test_business_transaction_commit_and_rollback_control_durable_intent_and_publication(): void
    {
        $user = $this->intakeCustomer();
        $resource = (string) Str::uuid7();
        DB::beginTransaction();
        $id = app(NotificationRecorder::class)->record($user->id, 'project.created', 'project', $resource, 'create:'.$resource, (string) Str::uuid7());
        $this->assertDatabaseHas('notifications', ['id' => $id]);
        Queue::assertNothingPushed();
        DB::rollBack();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertDatabaseCount('async_operations', 0);
        Queue::assertNothingPushed();
        $first = DB::transaction(fn () => app(NotificationRecorder::class)->record($user->id, 'project.created', 'project', $resource, 'create:'.$resource, (string) Str::uuid7()));
        $second = DB::transaction(fn () => app(NotificationRecorder::class)->record($user->id, 'project.created', 'project', $resource, 'create:'.$resource, (string) Str::uuid7()));
        self::assertSame($first, $second);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertDatabaseCount('async_operations', 1);
        Queue::assertPushed(RunOperationJob::class, 1);
        self::assertSame(0, $this->provider->calls);
    }

    public function test_logical_dedupe_rejects_changed_resource_and_payload_contains_only_safe_template_and_identifiers(): void
    {
        [$user,$delivery] = $this->fixture();
        try {
            DB::transaction(fn () => app(NotificationRecorder::class)->record($user->id, 'project.created', 'project', (string) Str::uuid7(), 'stable-event', (string) Str::uuid7()));
            self::fail('Logical event was reused for another resource.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
        $this->assertDatabaseCount('notifications', 1);
        $references = json_decode(DB::table('async_operations')->where('id', $delivery->operation_id)->value('references'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['delivery_id' => $delivery->id], $references);
        app(OperationRunner::class)->run($delivery->operation_id);
        self::assertSame('Sign in to HOLOUL to review this update.', $this->provider->last->body);
        self::assertSame($user->email, $this->provider->last->recipient);
    }

    public function test_known_pre_acceptance_outage_retries_with_bounded_backoff_and_duplicate_queue_delivery_does_not_resend(): void
    {
        [, $delivery] = $this->fixture();
        $this->provider->mode = 'unavailable';
        app(OperationRunner::class)->run($delivery->operation_id);
        self::assertSame('pending', $delivery->refresh()->state);
        $operation = DB::table('async_operations')->where('id', $delivery->operation_id)->select('*')->selectRaw('extract(epoch FROM next_attempt_at-clock_timestamp()) AS delay')->first();
        self::assertSame('pending', $operation->state);
        self::assertGreaterThan(20, (float) $operation->delay);
        self::assertLessThanOrEqual(40, (float) $operation->delay);
        self::assertSame('provider_unavailable', $operation->failure_code);
        app(OperationRunner::class)->run($delivery->operation_id);
        self::assertSame(1, $this->provider->calls);
        $this->provider->mode = 'accepted';
        $this->due($delivery);
        app(OperationRunner::class)->run($delivery->operation_id);
        app(OperationRunner::class)->run($delivery->operation_id);
        self::assertSame(2, $this->provider->calls);
        self::assertSame('accepted', $delivery->refresh()->state);
        self::assertSame(['failed', 'accepted'], DB::table('notification_delivery_attempts')->orderBy('attempt_number')->pluck('state')->all());
        $this->assertDatabaseHas('audit_events', ['event_type' => 'notifications.email_accepted', 'subject_id' => $delivery->id]);
    }

    public function test_known_permanent_rejection_is_terminal_and_attempts_are_immutable(): void
    {
        [, $delivery] = $this->fixture();
        $this->provider->mode = 'rejected';
        app(OperationRunner::class)->run($delivery->operation_id);
        app(OperationRunner::class)->run($delivery->operation_id);
        self::assertSame('failed', $delivery->refresh()->state);
        self::assertSame(1, $this->provider->calls);
        try {
            DB::transaction(fn () => DB::table('notification_delivery_attempts')->update(['state' => 'accepted']));
            self::fail('Terminal attempt was rewritten.');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->getCode());
        }
    }

    public function test_retry_budget_exhaustion_stops_five_proven_nonaccepted_attempts(): void
    {
        [, $delivery] = $this->fixture();
        $this->provider->mode = 'unavailable';
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->due($delivery);
            app(OperationRunner::class)->run($delivery->operation_id);
        }
        self::assertSame('failed', $delivery->refresh()->state);
        self::assertSame(5, $this->provider->calls);
        $this->assertDatabaseCount('notification_delivery_attempts', 5);
        $this->assertDatabaseHas('async_operations', ['id' => $delivery->operation_id, 'state' => 'failed', 'attempts' => 5]);
    }

    public function test_uncertain_acknowledgement_never_automatically_resends_or_persists_provider_exception(): void
    {
        [, $delivery] = $this->fixture();
        $this->provider->mode = 'uncertain';
        app(OperationRunner::class)->run($delivery->operation_id);
        app(OperationRunner::class)->run($delivery->operation_id);
        self::assertSame(1, $this->provider->calls);
        self::assertSame('uncertain', $delivery->refresh()->state);
        $this->assertDatabaseHas('notification_delivery_attempts', ['state' => 'uncertain', 'failure_code' => 'acknowledgement_uncertain']);
        self::assertStringNotContainsString('Private provider', json_encode(DB::table('notification_delivery_attempts')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_worker_crash_after_outbound_send_is_classified_uncertain_before_another_send(): void
    {
        [, $delivery] = $this->fixture();
        $claim = app(OperationRunner::class)->claim($delivery->operation_id);
        self::assertNotNull($claim);
        app(DeliverNotification::class)->execute($claim); // ACK received, result writer lost with the worker.
        self::assertSame('processing', $delivery->refresh()->state);
        DB::table('async_operations')->where('id', $delivery->operation_id)->update(['lease_expires_at' => now()->subSecond()]);
        app(OperationRunner::class)->run($delivery->operation_id);
        self::assertSame('uncertain', $delivery->refresh()->state);
        self::assertSame(1, $this->provider->calls);
        $this->assertDatabaseCount('notification_delivery_attempts', 1);
    }

    public function test_reconciliation_handles_final_claim_crash_without_outbound_replay(): void
    {
        [, $delivery] = $this->fixture();
        $claim = app(OperationRunner::class)->claim($delivery->operation_id);
        app(DeliverNotification::class)->execute($claim);
        DB::table('async_operations')->where('id', $delivery->operation_id)->update(['attempts' => 5, 'lease_expires_at' => now()->subSecond()]);
        app(OperationRunner::class)->run($delivery->operation_id);
        self::assertSame(1, app(ReconcileNotifications::class)->handle());
        self::assertSame(0, app(ReconcileNotifications::class)->handle());
        self::assertSame('uncertain', $delivery->refresh()->state);
        self::assertSame(1, $this->provider->calls);
    }

    public function test_failed_local_commit_after_provider_acceptance_is_uncertain_without_blind_resend(): void
    {
        [, $delivery] = $this->fixture();
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION notification_test_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.event_type='notifications.email_accepted' THEN RAISE EXCEPTION 'Injected audit persistence failure'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER notification_test_audit_failure BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION notification_test_audit_failure();
            SQL);
        try {
            app(OperationRunner::class)->run($delivery->operation_id);
            self::assertSame('processing', $delivery->refresh()->state);
            self::assertSame(1, app(ReconcileNotifications::class)->handle());
            self::assertSame('uncertain', $delivery->refresh()->state);
            $this->due($delivery);
            app(OperationRunner::class)->run($delivery->operation_id);
            self::assertSame(1, $this->provider->calls);
            $this->assertDatabaseHas('notification_delivery_attempts', ['state' => 'uncertain']);
        } finally {
            DB::unprepared('DROP TRIGGER notification_test_audit_failure ON audit_events; DROP FUNCTION notification_test_audit_failure();');
        }
    }

    #[DataProvider('suppressionModes')]
    public function test_delivery_rechecks_current_recipient_preference_and_configuration(string $mode, string $failure): void
    {
        [$user, $delivery] = $this->fixture();
        match ($mode) {
            'disabled' => $user->forceFill(['enabled' => false])->save(),
            'unverified' => $user->forceFill(['email_verified_at' => null])->save(),
            'preference' => DB::table('notification_preferences')->insert(['user_id' => $user->id, 'workflow_email' => false]),
            'configuration' => Config::set('notifications.email_enabled', false),
        };
        app(OperationRunner::class)->run($delivery->operation_id);
        self::assertSame('suppressed', $delivery->refresh()->state);
        self::assertSame($failure, $delivery->failure_code);
        self::assertSame(0, $this->provider->calls);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_delivery_attempts', 0);
    }

    public static function suppressionModes(): array
    {
        return [['disabled', 'recipient_unavailable'], ['unverified', 'recipient_unavailable'], ['preference', 'preference_disabled'], ['configuration', 'email_disabled']];
    }

    private function fixture(): array
    {
        $user = $this->intakeCustomer();
        $notice = DB::transaction(fn () => app(NotificationRecorder::class)->record($user->id, 'project.created', 'project', (string) Str::uuid7(), 'stable-event', (string) Str::uuid7()));

        return [$user, NotificationDelivery::query()->where('notification_id', $notice)->firstOrFail()];
    }

    private function due(NotificationDelivery $delivery): void
    {
        DB::table('async_operations')->where('id', $delivery->operation_id)->update(['next_attempt_at' => now()->subSecond()]);
    }
}
