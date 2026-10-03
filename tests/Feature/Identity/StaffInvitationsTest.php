<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Infrastructure\Async\OperationRunner;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Recovery\Contracts\MailTransport;
use App\Modules\Identity\Recovery\Data\RecoveryMessage;
use App\Modules\Identity\Staff\StaffInvitation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

final class StaffInvitationsTest extends TestCase
{
    use CommercialDatabase, IdentityHttp;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->initializeBrowser();
    }

    public function test_directory_and_capabilities_preserve_legacy_identity_shape_and_do_not_reissue_cookies(): void
    {
        $this->operator(Role::Administrator);
        $response = $this->browser('GET', '/api/v1/identity/staff?limit=1')->assertOk()->assertJsonPath('meta.total', 1);
        self::assertSame([], $response->headers->getCookies());
        $caps = $this->browser('GET', '/api/v1/identity/capabilities')->assertOk();
        self::assertContains('staff.view', $caps->json('data.capabilities'));
        self::assertSame(['portfolio_editor', 'support'], $caps->json('data.assignable_roles'));
        $me = $this->browser('GET', '/api/v1/identity/me')->assertOk();
        self::assertArrayNotHasKey('assignable_roles', $me->json('data'));
        self::assertNotContains('staff.view', $me->json('data.capabilities'));
    }

    public function test_issue_is_idempotent_and_duplicate_email_requires_explicit_resend(): void
    {
        $this->operator();
        $input = $this->input();
        $key = (string) Str::uuid7();
        $one = $this->browser('POST', '/api/v1/identity/staff/invitations', $input, ['Idempotency-Key' => $key])->assertCreated();
        $two = $this->browser('POST', '/api/v1/identity/staff/invitations', $input, ['Idempotency-Key' => $key])->assertCreated();
        self::assertSame($one->json('data.id'), $two->json('data.id'));
        $this->assertDatabaseCount('identity_staff_invitation_mail', 1);
        $this->browser('POST', '/api/v1/identity/staff/invitations', [...$input, 'full_name' => 'Another Name'], ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('error.reason', 'IDEMPOTENCY_KEY_REUSED');
        $this->browser('POST', '/api/v1/identity/staff/invitations', $input, ['Idempotency-Key' => (string) Str::uuid7()])
            ->assertConflict()->assertJsonPath('error.reason', 'INVITATION_ALREADY_PENDING')->assertJsonPath('error.resource_id', $one->json('data.id'));
        $this->assertDatabaseCount('users', 1);
        self::assertNull(StaffInvitation::query()->sole()->token_hash);
    }

    public function test_admin_invites_support_but_cannot_invite_security_or_business_roles(): void
    {
        $this->operator(Role::Administrator);
        $this->browser('POST', '/api/v1/identity/staff/invitations', $this->input(), ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        foreach (['administrator' => 'SECURITY_ROLE_RESTRICTED', 'super_admin' => 'SECURITY_ROLE_RESTRICTED', 'reviewer' => 'ROLE_AUTHORITY_EXCEEDED', 'customer' => 'STAFF_CUSTOMER_ROLE_MIX'] as $role => $reason) {
            $this->browser('POST', '/api/v1/identity/staff/invitations', [...$this->input(), 'roles' => [$role]], ['Idempotency-Key' => (string) Str::uuid7()])
                ->assertForbidden()->assertJsonPath('error.reason', $reason);
        }
    }

    public function test_email_conflicts_do_not_promote_customer_or_reenable_staff(): void
    {
        $actor = $this->operator();
        $customer = $this->customerUser();
        foreach ([$actor->email => 'EMAIL_ALREADY_STAFF', $customer->email => 'EMAIL_ALREADY_CUSTOMER'] as $email => $reason) {
            $this->browser('POST', '/api/v1/identity/staff/invitations', [...$this->input(), 'email' => $email], ['Idempotency-Key' => (string) Str::uuid7()])
                ->assertConflict()->assertJsonPath('error.reason', $reason);
        }
        $this->assertDatabaseCount('identity_staff_invitations', 0);
    }

    public function test_acceptance_proves_email_but_grants_no_access_until_real_mfa_completion(): void
    {
        $this->operator();
        $invite = $this->issue(['super_admin']);
        $token = $this->deliver($invite);
        $this->initializeBrowser();
        $lookup = $this->browser('POST', '/api/v1/auth/staff-invitations/lookup', ['token' => $token])->assertOk();
        self::assertSame([], $lookup->headers->getCookies());
        $accept = $this->browser('POST', '/api/v1/auth/staff-invitations/accept', $this->acceptInput($token))->assertOk();
        self::assertSame([], $accept->headers->getCookies());
        $user = User::query()->where('email', $invite->email)->sole();
        self::assertNotNull($user->email_verified_at);
        self::assertSame([Role::SuperAdmin], app(RoleAuthority::class)->roles($user->id));
        self::assertNull($invite->refresh()->activated_at);
        self::assertSame(0, DB::table('identity_sessions')->where('user_id', $user->id)->count());
        $this->browser('POST', '/api/v1/auth/staff-invitations/lookup', ['token' => $token])->assertUnprocessable()->assertJsonPath('error.reason', 'INVITATION_UNAVAILABLE');
        $this->signIn($user)->assertAccepted()->assertJsonPath('data.next_step', 'mfa_enrollment');
        $cookies = $this->browserCookies;
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        $this->initializeBrowser();
        $this->signIn($user)->assertAccepted();
        $this->enroll();
        self::assertSame([Role::SuperAdmin], app(RoleAuthority::class)->roles($user->id));
        self::assertNotNull($invite->refresh()->activated_at);
        $this->browser('GET', '/api/v1/identity/staff')->assertOk();
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.staff_invitation.activated', 'subject_id' => $invite->id]);
    }

    public function test_signed_in_browser_is_not_replaced_by_invitation_acceptance(): void
    {
        $actor = $this->operator();
        $invite = $this->issue();
        $token = $this->deliver($invite);
        $cookies = $this->browserCookies;
        $response = $this->browser('POST', '/api/v1/auth/staff-invitations/accept', $this->acceptInput($token))
            ->assertConflict()->assertJsonPath('error.reason', 'INVITATION_SIGN_OUT_REQUIRED');
        self::assertSame([], $response->headers->getCookies());
        self::assertSame($cookies, $this->browserCookies);
        $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.id', $actor->id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_resend_and_revoke_are_versioned_and_invalidate_old_tokens_immediately(): void
    {
        $this->operator();
        $invite = $this->issue();
        $old = $this->deliver($invite);
        $id = $invite->id;
        $this->browser('POST', "/api/v1/identity/staff/invitations/$id/resends", [], ['If-Match' => '"1"'])->assertStatus(412);
        $etag = '"'.$invite->refresh()->lock_version.'"';
        $this->browser('POST', "/api/v1/identity/staff/invitations/$id/resends", [], ['If-Match' => $etag])->assertStatus(429)->assertJsonPath('error.reason', 'INVITATION_RESEND_LIMIT');
        DB::table('identity_staff_invitations')->where('id', $id)->update(['last_requested_at' => now()->subMinutes(2)]);
        $this->browser('POST', "/api/v1/identity/staff/invitations/$id/resends", [], ['If-Match' => $etag])->assertOk();
        self::assertNull($invite->refresh()->token_hash);
        $new = $this->deliver($invite);
        self::assertNotSame($old, $new);
        $this->browser('POST', "/api/v1/identity/staff/invitations/$id/revocations", [], ['If-Match' => '"'.$invite->refresh()->lock_version.'"'])->assertOk();
        $this->initializeBrowser();
        foreach ([$old, $new, str_repeat('0', 64)] as $token) {
            $this->browser('POST', '/api/v1/auth/staff-invitations/lookup', ['token' => $token])->assertUnprocessable()->assertJsonPath('error.reason', 'INVITATION_UNAVAILABLE');
        }
    }

    public function test_current_inviter_authority_is_rechecked_at_acceptance(): void
    {
        $actor = $this->operator();
        $invite = $this->issue();
        $token = $this->deliver($invite);
        DB::table('users')->where('id', $actor->id)->update(['enabled' => false]);
        $this->initializeBrowser();
        $this->browser('POST', '/api/v1/auth/staff-invitations/accept', $this->acceptInput($token))->assertUnprocessable()->assertJsonPath('error.reason', 'INVITATION_UNAVAILABLE');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_guarded_role_edits_detect_legacy_writes_and_self_change_ends_only_current_identity(): void
    {
        $actor = $this->operator();
        $target = $this->customerUser();
        $target->kind = 'staff';
        $target->save();
        DB::table('user_roles')->insert(['user_id' => $target->id, 'role_id' => app(RoleAuthority::class)->roleId(Role::Support), 'user_kind' => 'staff']);
        $url = '/api/v1/identity/staff/'.$target->id.'/authorization';
        $this->browser('GET', $url)->assertOk()->assertHeader('ETag', '"1"');
        $this->browser('PUT', $url, ['roles' => ['reviewer'], 'enabled' => true])->assertStatus(428);
        $this->browser('PATCH', $url, ['roles' => ['reviewer'], 'enabled' => true])->assertOk();
        $this->browser('PUT', $url, ['roles' => ['support'], 'enabled' => true], ['If-Match' => '"1"'])->assertStatus(412);
        $this->browser('PUT', $url, ['roles' => ['reviewer'], 'enabled' => true], ['If-Match' => '"2"'])->assertOk()->assertJsonPath('meta.changed', false);
        $this->browser('PUT', '/api/v1/identity/staff/'.strtoupper($actor->id).'/authorization', ['roles' => ['super_admin', 'support'], 'enabled' => true], ['If-Match' => '"1"'])
            ->assertOk()->assertJsonPath('meta.current_session_revoked', true);
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
    }

    public function test_public_intake_requires_csrf_and_password_policy_without_issuing_session_cookies(): void
    {
        $this->operator();
        $invite = $this->issue();
        $token = $this->deliver($invite);
        $this->initializeBrowser();
        $this->browser('POST', '/api/v1/auth/staff-invitations/accept', $this->acceptInput($token), csrf: false)->assertForbidden();
        $this->browser('POST', '/api/v1/auth/staff-invitations/accept', ['token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password', 'error.fields');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_confirmable_actions_do_not_require_role_inference_and_confirmation_is_enforced(): void
    {
        $this->operator(Role::Administrator);
        $this->travel(6)->minutes();
        $caps = $this->browser('GET', '/api/v1/identity/capabilities')->assertOk();
        self::assertContains('staff.view', $caps->json('data.capabilities'));
        self::assertNotContains('staff.invitations.manage', $caps->json('data.capabilities'));
        self::assertContains('staff.invitations.manage', $caps->json('data.confirmable_capabilities'));
        $this->browser('POST', '/api/v1/identity/staff/invitations', $this->input(), ['Idempotency-Key' => (string) Str::uuid7()])
            ->assertForbidden()->assertJsonPath('error.reason', 'PASSWORD_CONFIRMATION_REQUIRED');
        $this->browser('POST', '/api/v1/auth/password/confirm', ['password' => 'Correct-Horse-72-River'])->assertOk();
        self::assertContains('staff.invitations.manage', $this->browser('GET', '/api/v1/identity/capabilities')->assertOk()->json('data.capabilities'));
        $this->travelBack();
    }

    public function test_unenrolled_accepted_super_admin_does_not_allow_removing_last_usable_admin(): void
    {
        $actor = $this->operator();
        $invite = $this->issue(['super_admin']);
        $token = $this->deliver($invite);
        $admin = $this->browserCookies;
        $this->initializeBrowser();
        $this->browser('POST', '/api/v1/auth/staff-invitations/accept', $this->acceptInput($token))->assertOk();
        $this->browserCookies = $admin;
        $response = $this->browser('PUT', '/api/v1/identity/staff/'.$actor->id.'/authorization', ['roles' => ['super_admin'], 'enabled' => false], ['If-Match' => '"1"'])
            ->assertConflict()->assertJsonPath('error.reason', 'LAST_ENABLED_SUPER_ADMIN');
        self::assertSame([], $response->headers->getCookies());
        $this->browser('GET', '/api/v1/identity/me')->assertOk();
    }

    public function test_filter_counts_literal_search_and_customer_denial(): void
    {
        $this->operator();
        $one = $this->issue();
        $this->issue();
        $response = $this->browser('GET', '/api/v1/identity/staff/invitations?limit=1')->assertOk()->assertJsonPath('meta.total', 2);
        $cursor = $response->json('meta.next_cursor');
        self::assertIsString($cursor);
        $this->browser('GET', '/api/v1/identity/staff/invitations?limit=1&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->browser('GET', '/api/v1/identity/staff/invitations?status=revoked&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->browser('GET', '/api/v1/identity/staff/invitations?q='.urlencode($one->email))->assertOk()->assertJsonPath('meta.total', 1);
        $this->browser('GET', '/api/v1/identity/staff/invitations?q=%25%25')->assertOk()->assertJsonPath('meta.total', 0);
        $this->initializeBrowser();
        $this->signIn($this->customerUser())->assertOk();
        $this->browser('GET', '/api/v1/identity/staff/invitations')->assertForbidden();
        $this->browser('GET', '/api/v1/identity/staff')->assertForbidden();
        self::assertSame([], $this->browser('GET', '/api/v1/identity/capabilities')->assertOk()->json('data.assignable_roles'));
    }

    public function test_pending_mfa_browser_cannot_accept_another_invitation(): void
    {
        $this->operator();
        $invite = $this->issue();
        $token = $this->deliver($invite);
        $this->initializeBrowser();
        $user = $this->customerUser();
        $user->kind = 'staff';
        $user->save();
        $this->signIn($user)->assertAccepted();
        $cookies = $this->browserCookies;
        $response = $this->browser('POST', '/api/v1/auth/staff-invitations/lookup', ['token' => $token])->assertConflict()->assertJsonPath('error.reason', 'INVITATION_SIGN_OUT_REQUIRED');
        self::assertSame([], $response->headers->getCookies());
        self::assertSame($cookies, $this->browserCookies);
        $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk();
    }

    public function test_uncertain_mail_is_not_retried_and_has_no_recoverable_token_storage(): void
    {
        $this->operator();
        $invite = $this->issue();
        $transport = new class implements MailTransport
        {
            public int $calls = 0;

            public function send(RecoveryMessage $message): void
            {
                $this->calls++;
                throw new \RuntimeException('Transport acknowledgement lost.');
            }
        };
        app()->instance(MailTransport::class, $transport);
        $operation = DB::table('identity_staff_invitation_mail')->where('invitation_id', $invite->id)->value('operation_id');
        app(OperationRunner::class)->run($operation);
        app(OperationRunner::class)->run($operation);
        self::assertSame(1, $transport->calls);
        $this->assertDatabaseHas('identity_staff_invitation_mail', ['invitation_id' => $invite->id, 'state' => 'uncertain']);
        $this->browser('GET', '/api/v1/identity/staff/invitations')->assertOk()->assertJsonPath('data.0.delivery_status', 'uncertain')->assertJsonPath('data.0.send_count', 0);
    }

    public function test_atomic_invitation_failure_rolls_back_roles_key_mail_and_durable_intent(): void
    {
        $this->operator();
        $before = DB::table('async_operations')->count();
        DB::statement("CREATE FUNCTION test_invite_failure() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        DB::statement('CREATE TRIGGER test_invite_failure BEFORE INSERT ON identity_staff_invitation_mail FOR EACH ROW EXECUTE FUNCTION test_invite_failure()');
        try {
            $this->browser('POST', '/api/v1/identity/staff/invitations', $this->input(), ['Idempotency-Key' => (string) Str::uuid7()])->assertStatus(500);
            $this->assertDatabaseCount('identity_staff_invitations', 0);
            $this->assertDatabaseCount('identity_staff_invitation_roles', 0);
            $this->assertDatabaseCount('identity_staff_invitation_keys', 0);
            self::assertSame($before, DB::table('async_operations')->count());
        } finally {
            DB::statement('DROP TRIGGER test_invite_failure ON identity_staff_invitation_mail');
            DB::statement('DROP FUNCTION test_invite_failure()');
        }
    }

    public function test_expiry_crossing_a_list_read_has_one_snapshot_then_expires_and_allows_new_issue(): void
    {
        $this->operator();
        $invite = $this->issue();
        $token = $this->deliver($invite);
        DB::table('identity_staff_invitations')->where('id', $invite->id)->update(['expires_at' => DB::raw("clock_timestamp() + interval '1 second'")]);
        $paused = false;
        DB::listen(function (QueryExecuted $query) use (&$paused): void {
            if (! $paused && str_contains($query->sql, 'identity_staff_invitations') && str_contains($query->sql, 'count(*)')) {
                $paused = true;
                DB::select('SELECT pg_sleep(1.1)');
            }
        });
        $this->browser('GET', '/api/v1/identity/staff/invitations?status=pending')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'pending');
        self::assertTrue($paused);
        $this->browser('GET', '/api/v1/identity/staff/invitations?status=expired')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'expired');
        $admin = $this->browserCookies;
        $this->initializeBrowser();
        $this->browser('POST', '/api/v1/auth/staff-invitations/accept', $this->acceptInput($token))->assertUnprocessable()->assertJsonPath('error.reason', 'INVITATION_UNAVAILABLE');
        $this->browserCookies = $admin;
        $this->browser('POST', '/api/v1/identity/staff/invitations', [...$this->input(), 'email' => $invite->email], ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        self::assertSame('expired', $invite->refresh()->status);
        self::assertNull($invite->token_hash);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.staff_invitation.expired', 'subject_id' => $invite->id]);
        $this->assertDatabaseCount('identity_staff_invitations', 2);
    }

    public function test_runtime_database_guards_retain_invitation_identity_roles_and_history(): void
    {
        $this->operator();
        $invite = $this->issue();
        $this->browser('POST', '/api/v1/identity/staff/invitations/'.$invite->id.'/revocations', [], ['If-Match' => '"'.$invite->lock_version.'"'])->assertOk();
        $writes = [
            [fn () => DB::table('identity_staff_invitations')->where('id', $invite->id)->update(['status' => 'pending', 'revoked_at' => null]), '23514'],
            [fn () => DB::table('identity_staff_invitations')->where('id', $invite->id)->update(['revoked_at' => now()->addDay()]), '23514'],
            [fn () => DB::table('identity_staff_invitations')->where('id', $invite->id)->update(['email' => 'rewritten@example.test']), '23514'],
            [fn () => DB::table('identity_staff_invitations')->where('id', $invite->id)->update(['roles_sealed' => false]), '23514'],
            [fn () => DB::table('identity_staff_invitation_roles')->insert(['invitation_id' => $invite->id, 'role_id' => app(RoleAuthority::class)->roleId(Role::Reviewer)]), '23514'],
            [fn () => DB::table('identity_staff_invitation_roles')->where('invitation_id', $invite->id)->delete(), '42501'],
            [fn () => DB::table('identity_staff_invitation_keys')->where('invitation_id', $invite->id)->update(['input_hash' => str_repeat('a', 64)]), '42501'],
            [fn () => DB::table('identity_staff_invitations')->where('id', $invite->id)->delete(), '42501'],
        ];
        foreach ($writes as [$write, $state]) {
            try {
                DB::transaction(function () use ($write): void {
                    DB::statement('SET LOCAL ROLE holoul_app');
                    $write();
                });
                self::fail('Runtime identity accepted an invalid invitation mutation.');
            } catch (\PDOException $failure) {
                self::assertSame($state, $failure->getCode());
            }
        }
        $migration = require database_path('migrations/2026_09_27_000000_add_staff_onboarding.php');
        try {
            DB::transaction(fn () => $migration->down());
            self::fail('Retained invitation history was rolled back.');
        } catch (\PDOException $failure) {
            self::assertSame('55000', $failure->getCode());
        }
        $this->assertDatabaseHas('identity_staff_invitations', ['id' => $invite->id, 'email' => $invite->email]);
    }

    public function test_directory_and_invitation_snapshots_retry_real_concurrent_session_touches_without_cookies(): void
    {
        $staff = $this->operator();
        Config::set('database.connections.staff_snapshot_peer', Config::array('database.connections.pgsql'));
        $peer = DB::connection('staff_snapshot_peer');
        self::assertNotSame(DB::scalar('SELECT pg_backend_pid()'), $peer->scalar('SELECT pg_backend_pid()'));
        $attempts = 0;
        $armed = false;
        $snapshotSeen = false;
        DB::listen(function (QueryExecuted $query) use (&$attempts, &$armed, &$snapshotSeen, $peer, $staff): void {
            if (! $armed || $query->connectionName !== 'pgsql' || DB::transactionLevel() !== 1) {
                return;
            }
            if ($query->sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') {
                $snapshotSeen = false;
            }
            if (! $snapshotSeen && str_contains($query->sql, 'from "users"')) {
                $snapshotSeen = true;
                $attempts++;
                if ($attempts === 1) {
                    // Commit after this read's PostgreSQL snapshot, before its session touch.
                    $peer->table('identity_sessions')->where('user_id', $staff->id)
                        ->update(['last_activity_at' => DB::raw("last_activity_at + interval '1 millisecond'")]);
                    // The session model stores seconds: ensure the main read really writes.
                    usleep(1_100_000);
                }
            }
        });
        try {
            foreach (['/api/v1/identity/staff', '/api/v1/identity/staff/invitations'] as $path) {
                $attempts = 0;
                $armed = true;
                $response = $this->browser('GET', $path)->assertOk();
                $armed = false;
                self::assertSame(2, $attempts);
                self::assertSame([], $response->headers->getCookies());
                self::assertSame(0, DB::transactionLevel());
                $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.id', $staff->id);
            }
        } finally {
            $armed = false;
            DB::purge('staff_snapshot_peer');
        }
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = $this->customerUser();
        $user->kind = 'staff';
        $user->email_verified_at = now();
        $user->save();
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => app(RoleAuthority::class)->roleId($role), 'user_kind' => 'staff']);
        $this->signIn($user)->assertAccepted();
        $this->enroll();

        return $user;
    }

    private function enroll(): void
    {
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }

    /** @return array<string,mixed> */
    public function test_super_admin_can_offer_all_eight_staff_roles_without_losing_invitation_sealing(): void
    {
        $this->operator();
        $roles = array_values(array_filter(array_column(Role::cases(), 'value'), fn (string $role): bool => $role !== 'customer'));
        self::assertCount(8, $roles);
        $invitation = $this->issue($roles);
        self::assertSame(8, DB::table('identity_staff_invitation_roles')->where('invitation_id', $invitation->id)->count());
        self::assertTrue($invitation->roles_sealed);
    }

    private function input(): array
    {
        return ['email' => Str::uuid7().'@example.test', 'full_name' => 'New Staff', 'roles' => ['support']];
    }

    /** @param list<string> $roles */
    private function issue(array $roles = ['support']): StaffInvitation
    {
        $id = $this->browser('POST', '/api/v1/identity/staff/invitations', [...$this->input(), 'roles' => $roles], ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('data.id');

        return StaffInvitation::query()->findOrFail($id);
    }

    private function deliver(StaffInvitation $invite): string
    {
        $transport = new class implements MailTransport
        {
            public ?RecoveryMessage $message = null;

            public function send(RecoveryMessage $message): void
            {
                $this->message = $message;
            }
        };
        app()->instance(MailTransport::class, $transport);
        $operation = DB::table('identity_staff_invitation_mail')->where('invitation_id', $invite->id)->orderByDesc('generation')->value('operation_id');
        app(OperationRunner::class)->run($operation);
        self::assertNotNull($transport->message);
        self::assertSame(1, preg_match('/#token=([a-f0-9]{64})/', $transport->message->body, $matches));
        $token = $matches[1];
        self::assertSame(hash('sha256', $token), $invite->refresh()->token_hash);
        $stored = json_encode([DB::table('identity_staff_invitations')->get(), DB::table('identity_staff_invitation_mail')->get(), DB::table('async_operations')->get(), DB::table('audit_events')->get()], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($token, $stored);

        return $token;
    }

    /** @return array<string,string> */
    private function acceptInput(string $token): array
    {
        return ['token' => $token, 'password' => 'Correct-Horse-72-River', 'password_confirmation' => 'Correct-Horse-72-River'];
    }
}
