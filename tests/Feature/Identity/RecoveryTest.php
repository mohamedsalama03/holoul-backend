<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Infrastructure\Async\AsyncOperation;
use App\Infrastructure\Async\OperationHandlerRegistry;
use App\Infrastructure\Async\OperationReconciler;
use App\Infrastructure\Async\OperationRunner;
use App\Infrastructure\Async\OperationState;
use App\Infrastructure\Async\RunOperationJob;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Recovery\Contracts\MailTransport;
use App\Modules\Identity\Recovery\Data\RecoveryMailPayload;
use App\Modules\Identity\Recovery\Data\RecoveryMessage;
use App\Modules\Identity\Recovery\Models\RecoveryMail;
use App\Modules\Identity\Recovery\Models\RecoveryToken;
use App\Modules\Identity\Recovery\RecoveryActions;
use App\Modules\Identity\Recovery\RecoveryPurpose;
use App\Modules\Identity\Recovery\SendRecoveryMail;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class RecoveryTest extends TestCase
{
    use DatabaseMigrations;

    private RecoveryCaptureTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new RecoveryCaptureTransport;
        app()->instance(MailTransport::class, $this->transport);
        $registry = new OperationHandlerRegistry;
        $registry->register('identity.recovery_mail', app(SendRecoveryMail::class));
        app()->instance(OperationHandlerRegistry::class, $registry);
        Config::set('app.url', 'https://holoul.test');
    }

    public function test_registration_intent_is_atomic_and_published_only_after_commit(): void
    {
        Queue::fake();
        $user = $this->user();
        DB::beginTransaction();
        app(RecoveryActions::class)->issueVerification($user, $this->requestId());
        $mail = RecoveryMail::query()->sole();
        $payload = $this->payload($mail);
        $record = RecoveryToken::query()->sole();
        Queue::assertNothingPushed();
        $this->assertSame(hash('sha256', $payload->token), $record->token_hash);
        $this->assertSame(64, strlen($payload->token));
        $this->assertNotSame($payload->token, $record->token_hash);
        $this->assertStringNotContainsString($payload->token, $mail->encrypted_payload ?? '');
        $this->assertStringNotContainsString($user->email, $mail->encrypted_payload ?? '');
        $this->assertSame(RecoveryPurpose::EmailVerification, $record->purpose);
        $this->assertEqualsWithDelta(3600, $record->created_at->diffInSeconds($record->expires_at), 1);
        DB::commit();
        Queue::assertPushed(RunOperationJob::class, fn (RunOperationJob $job): bool => $job->operationId === $mail->operation_id);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.verification.issued', 'subject_id' => $user->id]);
        $operation = AsyncOperation::query()->findOrFail($mail->operation_id);
        $this->assertSame(['mail_id' => $mail->id], $operation->references);
    }

    public function test_rollback_removes_token_mail_operation_and_audit(): void
    {
        Queue::fake();
        $user = $this->user();
        DB::beginTransaction();
        app(RecoveryActions::class)->issueVerification($user, $this->requestId());
        DB::rollBack();
        $this->assertDatabaseCount('identity_recovery_tokens', 0);
        $this->assertDatabaseCount('identity_recovery_mail', 0);
        $this->assertDatabaseCount('async_operations', 0);
        $this->assertDatabaseCount('audit_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_email_verification_is_idempotent_and_resend_supersedes_older_links(): void
    {
        Queue::fake();
        $user = $this->user();
        $actions = app(RecoveryActions::class);
        $actions->issueVerification($user, $this->requestId());
        $first = RecoveryMail::query()->sole();
        $oldToken = $this->payload($first)->token;
        $actions->resendVerification($user->email, $this->requestId());
        $this->assertSame('discarded', $first->refresh()->state);
        $this->assertNull($first->encrypted_payload);
        $this->assertInvalid(fn () => $actions->verifyEmail($oldToken, $this->requestId()));
        $freshToken = $this->payload(RecoveryMail::query()->where('state', 'pending')->sole())->token;
        $actions->verifyEmail($freshToken, $this->requestId());
        $actions->verifyEmail($freshToken, $this->requestId());
        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'identity.email.verified')->count());
        $actions->resendVerification($user->email, $this->requestId());
        $this->assertDatabaseCount('identity_recovery_mail', 2);
    }

    public function test_tokens_are_bound_to_purpose_expiry_account_email_and_credential_generation(): void
    {
        Queue::fake();
        $user = $this->user();
        $actions = app(RecoveryActions::class);
        $actions->forgotPassword($user->email, $this->requestId());
        $token = $this->payload(RecoveryMail::query()->sole())->token;
        $this->assertInvalid(fn () => $actions->verifyEmail($token, $this->requestId()));
        User::query()->whereKey($user->id)->update(['email' => 'changed@example.test']);
        $this->assertInvalid(fn () => $actions->resetPassword($token, 'A replacement password 2026!', $this->requestId()));
        User::query()->whereKey($user->id)->update(['email' => $user->email, 'auth_version' => 2]);
        $this->assertInvalid(fn () => $actions->resetPassword($token, 'A replacement password 2026!', $this->requestId()));
        User::query()->whereKey($user->id)->update(['auth_version' => 1]);
        RecoveryToken::query()->update(['created_at' => DB::raw("clock_timestamp() - interval '2 hours'"), 'expires_at' => DB::raw("clock_timestamp() - interval '1 hour'")]);
        $this->assertInvalid(fn () => $actions->resetPassword($token, 'A replacement password 2026!', $this->requestId()));
        $this->assertInvalid(fn () => $actions->verifyEmail('malformed', $this->requestId()));
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'identity.password.reset']);
    }

    public function test_unknown_disabled_and_already_verified_accounts_do_not_create_mail(): void
    {
        Queue::fake();
        $disabled = $this->user('disabled@example.test', false);
        $verified = $this->user('verified@example.test');
        $verified->email_verified_at = now()->toImmutable();
        $verified->save();
        $actions = app(RecoveryActions::class);
        foreach (['unknown@example.test', $disabled->email] as $email) {
            $actions->forgotPassword($email, $this->requestId());
            $actions->resendVerification($email, $this->requestId());
        }
        $actions->resendVerification($verified->email, $this->requestId());
        $this->assertDatabaseCount('identity_recovery_mail', 0);
        $this->assertDatabaseCount('identity_recovery_tokens', 0);
        $this->assertDatabaseCount('audit_events', 5);
        Queue::assertNothingPushed();
    }

    public function test_password_reset_is_single_use_revokes_all_sessions_and_never_logs_in(): void
    {
        Queue::fake();
        $user = $this->user();
        $other = $this->user('other@example.test');
        foreach ([$user, $other] as $account) {
            DB::table('identity_sessions')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $account->id,
                'session_hash' => hash('sha256', $account->id), 'auth_version' => 1,
                'authenticated_at' => now(), 'last_activity_at' => now(), 'expires_at' => now()->addHour(),
            ]);
            DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $account->id, 'payload' => '', 'last_activity' => time()]);
        }
        $actions = app(RecoveryActions::class);
        $actions->forgotPassword($user->email, $this->requestId());
        $token = $this->payload(RecoveryMail::query()->sole())->token;
        $actions->resetPassword($token, 'A replacement password 2026!', $this->requestId());
        $this->assertTrue(Hash::check('A replacement password 2026!', $user->refresh()->password));
        $this->assertSame(2, $user->auth_version);
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertDatabaseMissing('identity_sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('identity_sessions', ['user_id' => $other->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
        $this->assertInvalid(fn () => $actions->resetPassword($token, 'An attacker replacement 2026!', $this->requestId()));
        $this->assertTrue(Hash::check('A replacement password 2026!', $user->refresh()->password));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'identity.password.reset')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'identity.sessions_revoked')->count());
    }

    public function test_independent_postgresql_reset_race_cannot_consume_the_same_token_twice(): void
    {
        Queue::fake();
        $user = $this->user();
        $actions = app(RecoveryActions::class);
        $actions->forgotPassword($user->email, $this->requestId());
        $token = $this->payload(RecoveryMail::query()->sole())->token;
        $primary = DB::getDefaultConnection();
        Config::set('database.connections.recovery_peer', Config::array('database.connections.'.$primary));
        DB::connection('recovery_peer')->statement("SET lock_timeout = '100ms'");
        DB::beginTransaction();
        try {
            $actions->resetPassword($token, 'The winning password 2026!', $this->requestId());
            DB::setDefaultConnection('recovery_peer');
            try {
                $actions->resetPassword($token, 'The losing password 2026!', $this->requestId());
                $this->fail('A reset bypassed the account row lock.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            } finally {
                DB::setDefaultConnection($primary);
            }
            DB::commit();
            DB::setDefaultConnection('recovery_peer');
            $this->assertInvalid(fn () => $actions->resetPassword($token, 'The losing password 2026!', $this->requestId()));
        } finally {
            DB::setDefaultConnection($primary);
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('recovery_peer');
        }
        $this->assertTrue(Hash::check('The winning password 2026!', $user->refresh()->password));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'identity.password.reset')->count());
    }

    public function test_audit_failure_rolls_back_password_token_and_session_generation_together(): void
    {
        Queue::fake();
        $user = $this->user();
        $originalHash = $user->password;
        $actions = app(RecoveryActions::class);
        $actions->forgotPassword($user->email, $this->requestId());
        $token = $this->payload(RecoveryMail::query()->sole())->token;
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT recovery_test_audit_failure CHECK (event_type <> 'identity.password.reset')");
        try {
            try {
                $actions->resetPassword($token, 'A replacement password 2026!', $this->requestId());
                $this->fail('A password reset committed without its audit event.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
            $this->assertSame($originalHash, $user->refresh()->password);
            $this->assertSame(1, $user->auth_version);
            $this->assertNull(RecoveryToken::query()->sole()->consumed_at);
            $this->assertSame('pending', RecoveryMail::query()->sole()->state);
            $this->assertDatabaseMissing('audit_events', ['event_type' => 'identity.sessions_revoked']);
        } finally {
            DB::statement('ALTER TABLE audit_events DROP CONSTRAINT recovery_test_audit_failure');
        }
        $actions->resetPassword($token, 'A replacement password 2026!', $this->requestId());
        $this->assertSame(2, $user->refresh()->auth_version);
    }

    public function test_mail_sent_state_rolls_back_with_audit_and_operation_success_and_does_not_resend(): void
    {
        Queue::fake();
        app(RecoveryActions::class)->issueVerification($this->user(), $this->requestId());
        $mail = RecoveryMail::query()->sole();
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT recovery_test_mail_audit_failure CHECK (event_type <> 'identity.recovery.mail_sent')");
        try {
            app(OperationRunner::class)->run($mail->operation_id);
            $this->assertSame('sending', $mail->refresh()->state);
            $this->assertNull($mail->sent_at);
            $this->assertNotNull($mail->encrypted_payload);
            $this->assertSame(OperationState::Pending, AsyncOperation::query()->findOrFail($mail->operation_id)->state);
            $this->assertCount(1, $this->transport->messages);
        } finally {
            DB::statement('ALTER TABLE audit_events DROP CONSTRAINT recovery_test_mail_audit_failure');
        }
        AsyncOperation::query()->whereKey($mail->operation_id)->update(['next_attempt_at' => DB::raw('clock_timestamp()')]);
        app(OperationRunner::class)->run($mail->operation_id);
        $this->assertSame('uncertain', $mail->refresh()->state);
        $this->assertNull($mail->encrypted_payload);
        $this->assertCount(1, $this->transport->messages);
    }

    public function test_duplicate_mail_delivery_sends_once_and_clears_payload_atomically(): void
    {
        Queue::fake();
        app(RecoveryActions::class)->issueVerification($this->user(), $this->requestId());
        $mail = RecoveryMail::query()->sole();
        $token = $this->payload($mail)->token;
        $runner = app(OperationRunner::class);
        $runner->run($mail->operation_id);
        $runner->run($mail->operation_id);
        $this->assertCount(1, $this->transport->messages);
        $this->assertSame([0], $this->transport->transactionLevels);
        $this->assertStringContainsString('https://holoul.test/verify-email#token='.$token, $this->transport->messages[0]->body);
        $this->assertSame('sent', $mail->refresh()->state);
        $this->assertNull($mail->encrypted_payload);
        $this->assertSame(OperationState::Succeeded, AsyncOperation::query()->findOrFail($mail->operation_id)->state);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'identity.recovery.mail_sent')->count());
        $persisted = json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($token, $persisted);
        $this->assertStringNotContainsString('member@example.test', $persisted);
    }

    public function test_expired_mail_is_discarded_without_sending(): void
    {
        Queue::fake();
        app(RecoveryActions::class)->forgotPassword($this->user()->email, $this->requestId());
        RecoveryToken::query()->update(['created_at' => DB::raw("clock_timestamp() - interval '2 hours'"), 'expires_at' => DB::raw("clock_timestamp() - interval '1 hour'")]);
        $mail = RecoveryMail::query()->sole();
        app(OperationRunner::class)->run($mail->operation_id);
        $this->assertCount(0, $this->transport->messages);
        $this->assertSame('discarded', $mail->refresh()->state);
        $this->assertNull($mail->encrypted_payload);
        $this->assertSame('token_unavailable', $mail->failure_code);
    }

    public function test_delivery_failure_is_safe_terminal_and_never_retried_blindly(): void
    {
        Queue::fake();
        app(RecoveryActions::class)->forgotPassword($this->user()->email, $this->requestId());
        $mail = RecoveryMail::query()->sole();
        $this->transport->fail = true;
        app(OperationRunner::class)->run($mail->operation_id);
        app(OperationRunner::class)->run($mail->operation_id);
        $this->assertSame('uncertain', $mail->refresh()->state);
        $this->assertNull($mail->encrypted_payload);
        $operation = AsyncOperation::query()->findOrFail($mail->operation_id);
        $this->assertSame(OperationState::Failed, $operation->state);
        $this->assertSame('mail_delivery_uncertain', $operation->failure_code);
        $this->assertSame(1, $this->transport->attempts);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.recovery.mail_uncertain']);
    }

    public function test_worker_crash_after_smtp_acceptance_cannot_repeat_external_send(): void
    {
        Queue::fake();
        app(RecoveryActions::class)->forgotPassword($this->user()->email, $this->requestId());
        $mail = RecoveryMail::query()->sole();
        $runner = app(OperationRunner::class);
        $claim = $runner->claim($mail->operation_id);
        $this->assertNotNull($claim);
        app(SendRecoveryMail::class)->execute($claim);
        $this->assertSame('sending', $mail->refresh()->state);
        AsyncOperation::query()->whereKey($mail->operation_id)->update(['lease_expires_at' => DB::raw("clock_timestamp() - interval '1 second'")]);
        $runner->run($mail->operation_id);
        $this->assertCount(1, $this->transport->messages);
        $this->assertSame('uncertain', $mail->refresh()->state);
        $this->assertNull($mail->encrypted_payload);
        $this->assertSame(OperationState::Failed, AsyncOperation::query()->findOrFail($mail->operation_id)->state);
    }

    public function test_invalid_encrypted_payload_is_cleared_and_never_sent(): void
    {
        Queue::fake();
        app(RecoveryActions::class)->issueVerification($this->user(), $this->requestId());
        $mail = RecoveryMail::query()->sole();
        RecoveryMail::query()->whereKey($mail->id)->update(['encrypted_payload' => 'corrupted-ciphertext']);
        app(OperationRunner::class)->run($mail->operation_id);
        $this->assertSame('discarded', $mail->refresh()->state);
        $this->assertNull($mail->encrypted_payload);
        $this->assertSame('mail_payload_invalid', AsyncOperation::query()->findOrFail($mail->operation_id)->failure_code);
        $this->assertCount(0, $this->transport->messages);
    }

    public function test_redis_loss_recovers_the_durable_mail_intent_and_transports_only_its_operation_id(): void
    {
        $queueName = 'recovery-test-'.Str::uuid7();
        Config::set('async.queue', $queueName);
        app(RecoveryActions::class)->issueVerification($this->user(), $this->requestId());
        $mail = RecoveryMail::query()->sole();
        $token = $this->payload($mail)->token;
        $queue = Queue::connection('redis');
        $lost = $queue->pop($queueName);
        $this->assertNotNull($lost);
        $lost->delete();
        AsyncOperation::query()->whereKey($mail->operation_id)->update(['last_dispatched_at' => DB::raw("clock_timestamp() - interval '10 minutes'")]);
        app(OperationReconciler::class)->reconcile();
        $recovered = $queue->pop($queueName);
        $this->assertNotNull($recovered);
        $this->assertStringContainsString($mail->operation_id, $recovered->getRawBody());
        $this->assertStringNotContainsString($token, $recovered->getRawBody());
        $this->assertStringNotContainsString('member@example.test', $recovered->getRawBody());
        $this->assertStringNotContainsString($mail->id, $recovered->getRawBody());
        try {
            $recovered->fire();
        } finally {
            $recovered->delete();
        }
        $this->assertSame('sent', $mail->refresh()->state);
        $this->assertCount(1, $this->transport->messages);
        $this->assertSame(0, $queue->size($queueName));
    }

    private function user(string $email = 'member@example.test', bool $enabled = true): User
    {
        return User::query()->create([
            'full_name' => 'Recovery Test', 'email' => $email, 'email_display' => $email,
            'password' => 'The original password 2026!', 'kind' => 'customer', 'enabled' => $enabled,
        ])->refresh();
    }

    private function payload(RecoveryMail $mail): RecoveryMailPayload
    {
        return RecoveryMailPayload::decrypt($mail->encrypted_payload ?? '', app(Encrypter::class));
    }

    private function requestId(): string
    {
        return (string) Str::uuid7();
    }

    /** @param callable(): void $action */
    private function assertInvalid(callable $action): void
    {
        try {
            $action();
            $this->fail('An invalid recovery token was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['token' => ['This link is invalid or expired.']], $exception->errors());
        }
    }
}

final class RecoveryCaptureTransport implements MailTransport
{
    /** @var list<RecoveryMessage> */
    public array $messages = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public bool $fail = false;

    public int $attempts = 0;

    public function send(RecoveryMessage $message): void
    {
        $this->attempts++;
        $this->transactionLevels[] = DB::transactionLevel();
        if ($this->fail) {
            throw new RuntimeException('Do not persist the secret recipient '.$message->recipient.' or body '.$message->body);
        }
        $this->messages[] = $message;
    }
}
