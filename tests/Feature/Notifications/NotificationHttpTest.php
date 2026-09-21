<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Infrastructure\Async\OperationRunner;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Recovery\RecoveryActions;
use App\Modules\Notifications\Contracts\EmailProvider;
use App\Modules\Notifications\Contracts\NotificationRecorder;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\Support\IntakeFixtures;
use Tests\Support\NotificationProviderDouble;
use Tests\TestCase;

final class NotificationHttpTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;
    use IntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBrowser();
        Queue::fake();
    }

    public function test_own_inbox_read_unread_all_read_and_foreign_notification_isolation(): void
    {
        $a = $this->intakeCustomer();
        $b = $this->intakeCustomer();
        $one = $this->notice($a);
        $two = $this->notice($a);
        $foreign = $this->notice($b);
        $this->signIn($a)->assertOk();
        $listing = $this->browser('GET', '/api/v1/notifications?limit=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertCount(1, $listing->json('data'));
        self::assertNotNull($listing->json('meta.next_after'));
        self::assertStringNotContainsString($foreign, $listing->getContent());
        $this->browser('GET', '/api/v1/notifications/'.$foreign)->assertNotFound();
        $this->browser('POST', '/api/v1/notifications/'.$foreign.'/read', [], $this->headers('"'.$foreign.':1"'))->assertNotFound();
        $this->browser('GET', '/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 2);
        $detail = $this->browser('GET', '/api/v1/notifications/'.$one)->assertOk();
        $headers = $this->headers($detail->headers->get('ETag'));
        $first = $this->browser('POST', '/api/v1/notifications/'.$one.'/read', [], $headers)->assertOk();
        $replay = $this->browser('POST', '/api/v1/notifications/'.$one.'/read', [], $headers)->assertOk();
        self::assertEquals($first->json(), $replay->json()); // JSONB object-key order is not part of the HTTP contract.
        self::assertSame($first->headers->get('ETag'), $replay->headers->get('ETag'));
        $count = $this->browser('GET', '/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 1);
        $this->browser('POST', '/api/v1/notifications/read-all', [], $this->headers($count->headers->get('ETag')))->assertOk()->assertJsonPath('data.marked_read', 1);
        $this->browser('GET', '/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 0);
        $this->assertDatabaseHas('notifications', ['id' => $foreign, 'read_at' => null]);
        self::assertNotNull(DB::table('notifications')->where('id', $two)->value('read_at'));
    }

    public function test_preferences_are_versioned_audited_and_cannot_disable_b2_security_mail(): void
    {
        $user = $this->intakeCustomer(false);
        $this->signIn($user)->assertOk();
        $preference = $this->browser('GET', '/api/v1/notifications/preferences')->assertOk()->assertJsonPath('data.workflow_email', true);
        $headers = $this->headers($preference->headers->get('ETag'));
        $this->browser('PATCH', '/api/v1/notifications/preferences', ['workflow_email' => false, 'security_messages_required' => false], $headers)->assertUnprocessable();
        $changed = $this->browser('PATCH', '/api/v1/notifications/preferences', ['workflow_email' => false], $headers)->assertOk()
            ->assertJsonPath('data.workflow_email', false)->assertJsonPath('data.security_messages_required', true);
        self::assertSame($changed->json(), $this->browser('PATCH', '/api/v1/notifications/preferences', ['workflow_email' => false], $headers)->assertOk()->json());
        $this->browser('PATCH', '/api/v1/notifications/preferences', ['workflow_email' => true], $this->headers($preference->headers->get('ETag')))->assertStatus(412);
        app(RecoveryActions::class)->issueVerification($user, (string) Str::uuid7());
        self::assertSame(1, DB::table('identity_recovery_mail')->where('user_id', $user->id)->where('state', 'pending')->count());
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'notifications.preferences_changed')->where('actor_id', $user->id)->count());
    }

    public function test_required_preconditions_unknown_inputs_and_session_csrf_controls_remain_enforced(): void
    {
        $user = $this->intakeCustomer();
        $id = $this->notice($user);
        $this->browser('GET', '/api/v1/notifications')->assertUnauthorized();
        $this->signIn($user)->assertOk();
        $this->browser('POST', '/api/v1/notifications/'.$id.'/read', [], ['Idempotency-Key' => (string) Str::uuid7()])->assertStatus(428);
        $this->browser('POST', '/api/v1/notifications/'.$id.'/read', [], ['If-Match' => '"'.$id.':1"'])->assertUnprocessable();
        $this->browser('GET', '/api/v1/notifications?recipient_id='.$user->id)->assertUnprocessable();
        $this->browser('GET', '/api/v1/notifications?limit=101')->assertUnprocessable();
        $this->browser('GET', '/api/v1/notifications/not-a-uuid')->assertNotFound();
        $this->browser('POST', '/api/v1/notifications/'.$id.'/read', [], [...$this->headers('"'.$id.':1"'), 'Origin' => 'https://foreign.test'])->assertForbidden();
        DB::table('identity_sessions')->where('user_id', $user->id)->delete();
        $this->browser('GET', '/api/v1/notifications')->assertUnauthorized();
    }

    public function test_uncertain_operator_replay_requires_permission_confirmation_and_preserves_logical_notification(): void
    {
        $user = $this->intakeCustomer();
        $notice = $this->notice($user);
        $delivery = NotificationDelivery::query()->where('notification_id', $notice)->firstOrFail();
        $provider = new NotificationProviderDouble;
        $provider->mode = 'uncertain';
        app()->instance(EmailProvider::class, $provider);
        app(OperationRunner::class)->run($delivery->operation_id);
        $this->signIn($user)->assertOk();
        $path = '/api/v1/admin/notification-deliveries/'.$delivery->id;
        $this->browser('GET', $path)->assertForbidden();
        $this->browser('POST', $path.'/replays', ['reason' => 'Verified rejection.', 'resolution' => 'confirmed_not_accepted'], $this->headers('"'.$delivery->id.':3"'))->assertForbidden();
        $admin = $this->intakeStaff('super_admin');
        $this->initializeBrowser();
        $this->staff($admin);
        $detail = $this->browser('GET', $path)->assertOk()->assertJsonPath('data.state', 'uncertain');
        $this->browser('POST', $path.'/replays', ['reason' => 'Retry without evidence.', 'resolution' => 'retry_failure'], $this->headers($detail->headers->get('ETag')))->assertUnprocessable();
        $headers = $this->headers($detail->headers->get('ETag'));
        $body = ['reason' => 'Operator confirmed provider did not accept the message.', 'resolution' => 'confirmed_not_accepted'];
        $replayed = $this->browser('POST', $path.'/replays', $body, $headers)->assertOk()->assertJsonPath('data.generation', 2);
        self::assertSame($replayed->json(), $this->browser('POST', $path.'/replays', $body, $headers)->assertOk()->json());
        $provider->mode = 'accepted';
        app(OperationRunner::class)->run($delivery->refresh()->operation_id);
        self::assertSame('accepted', $delivery->refresh()->state);
        self::assertSame(2, $provider->calls);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_replays', 1);
        self::assertSame(['uncertain', 'accepted'], DB::table('notification_delivery_attempts')->orderBy('generation')->pluck('state')->all());
        $this->assertDatabaseHas('audit_events', ['event_type' => 'notifications.delivery_replayed', 'actor_id' => $admin->id, 'subject_id' => $delivery->id]);
        $this->browser('POST', $path.'/replays', $body, $this->headers('"'.$delivery->id.':'.$delivery->lock_version.'"'))->assertConflict();
    }

    public function test_replay_receipt_cannot_bypass_revoked_operator_permission(): void
    {
        $user = $this->intakeCustomer();
        $notice = $this->notice($user);
        $delivery = NotificationDelivery::query()->where('notification_id', $notice)->firstOrFail();
        $provider = new NotificationProviderDouble;
        $provider->mode = 'rejected';
        app()->instance(EmailProvider::class, $provider);
        app(OperationRunner::class)->run($delivery->operation_id);
        $admin = $this->intakeStaff('super_admin');
        $this->staff($admin);
        $path = '/api/v1/admin/notification-deliveries/'.$delivery->id;
        $detail = $this->browser('GET', $path)->assertOk();
        $headers = $this->headers($detail->headers->get('ETag'));
        $body = ['reason' => 'Provider configuration corrected.', 'resolution' => 'retry_failure'];
        $this->browser('POST', $path.'/replays', $body, $headers)->assertOk();
        DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('code', 'notifications.delivery.replay')->value('id'))->delete();
        $this->browser('POST', $path.'/replays', $body, $headers)->assertForbidden();
        $this->assertDatabaseCount('notification_replays', 1);
    }

    private function notice(User $user): string
    {
        return DB::transaction(fn () => app(NotificationRecorder::class)->record($user->id, 'project.created', 'project', (string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()));
    }

    private function headers(?string $etag): array
    {
        return ['If-Match' => $etag, 'Idempotency-Key' => (string) Str::uuid7()];
    }

    private function staff(User $user): void
    {
        $this->signIn($user)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
        $this->browser('POST', '/api/v1/auth/password/confirm', ['password' => 'Correct-Horse-72-River'])->assertOk();
    }
}
