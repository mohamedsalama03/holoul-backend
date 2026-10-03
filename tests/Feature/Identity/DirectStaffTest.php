<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\PublicServices\PortfolioAuthorityAdapter;
use App\Infrastructure\Async\OperationRunner;
use App\Modules\Identity\Actions\ReadActiveIdentity;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Recovery\Contracts\MailTransport;
use App\Modules\Identity\Recovery\Data\RecoveryMessage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

final class DirectStaffTest extends TestCase
{
    use CommercialDatabase, IdentityHttp;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->initializeBrowser();
    }

    public function test_create_is_atomic_idempotent_and_does_not_replace_the_admin_session(): void
    {
        $actor = $this->operator();
        $before = $this->sessionId();
        $input = $this->input();
        $headers = ['Idempotency-Key' => (string) Str::uuid7()];
        $first = $this->browser('POST', '/api/v1/identity/staff', $input, $headers)->assertCreated();
        self::assertSame([], $first->headers->getCookies());
        $again = $this->browser('POST', '/api/v1/identity/staff', $input, $headers)->assertCreated();
        self::assertSame($first->json(), $again->json());
        self::assertSame($before, $this->sessionId());
        $this->browser('GET', '/api/v1/identity/me')->assertJsonPath('data.id', $actor->id);
        $this->browser('POST', '/api/v1/identity/staff', [...$input, 'password' => 'Different-Password-92', 'password_confirmation' => 'Different-Password-92'], $headers)
            ->assertConflict()->assertJsonPath('error.reason', 'IDEMPOTENCY_KEY_REUSED');
        $user = User::query()->findOrFail($first->json('data.id'));
        self::assertTrue(Hash::check($input['password'], $user->password));
        self::assertSame('ahmed.new', $user->username);
        self::assertNull($user->email_verified_at);
        $this->assertDatabaseCount('identity_staff_creation_keys', 1);
        $this->assertDatabaseCount('identity_recovery_mail', 0);
        $this->assertDatabaseCount('identity_staff_invitations', 0);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'identity.staff_account.created')->count());
        self::assertStringNotContainsString($input['password'], json_encode([
            $first->json(), DB::table('identity_staff_creation_keys')->get(), DB::table('audit_events')->get(),
            DB::table('async_operations')->get(), DB::table('identity_recovery_mail')->get(),
        ], JSON_THROW_ON_ERROR));
    }

    public function test_role_grants_remain_limited_and_anonymous_customers_cannot_create_staff(): void
    {
        $this->browser('POST', '/api/v1/identity/staff', $this->input())->assertUnauthorized();
        $this->signIn($this->customerUser())->assertOk();
        $this->browser('POST', '/api/v1/identity/staff', $this->input())->assertForbidden();
        $this->browser('POST', '/api/v1/auth/logout');
        $this->initializeBrowser();
        $this->operator(Role::Administrator);
        foreach (['super_admin', 'administrator', 'reviewer', 'customer'] as $role) {
            $this->browser('POST', '/api/v1/identity/staff', [...$this->input(), 'roles' => [$role]], ['Idempotency-Key' => (string) Str::uuid7()])->assertForbidden();
        }
        $this->browser('POST', '/api/v1/identity/staff', $this->input(), ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->assertDatabaseCount('identity_staff_creation_keys', 1);
    }

    public function test_validation_duplicate_username_and_email_do_not_mutate_other_accounts(): void
    {
        $this->operator();
        $input = $this->input();
        foreach ([['username' => 'ab'], ['username' => 'name@example.test'], ['password' => '12345678'], ['email' => 'bad'], ['enabled' => false]] as $delta) {
            $this->browser('POST', '/api/v1/identity/staff', [...$input, ...$delta], ['Idempotency-Key' => (string) Str::uuid7()])->assertUnprocessable();
        }
        $this->assertDatabaseCount('users', 1);
        $this->browser('POST', '/api/v1/identity/staff', $input, ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->browser('POST', '/api/v1/identity/staff', [...$input, 'email' => 'other@example.test'], ['Idempotency-Key' => (string) Str::uuid7()])
            ->assertConflict()->assertJsonPath('error.reason', 'USERNAME_UNAVAILABLE');
        $this->browser('POST', '/api/v1/identity/staff', [...$input, 'username' => 'someone.else'], ['Idempotency-Key' => (string) Str::uuid7()])
            ->assertConflict()->assertJsonPath('error.reason', 'EMAIL_ALREADY_STAFF');
        $customer = $this->customerUser();
        $this->browser('POST', '/api/v1/identity/staff', [...$input, 'username' => 'customer.alias', 'email' => $customer->email], ['Idempotency-Key' => (string) Str::uuid7()])
            ->assertConflict()->assertJsonPath('error.reason', 'EMAIL_ALREADY_CUSTOMER');
        self::assertSame('customer', $customer->fresh()->kind);
        $this->assertDatabaseCount('identity_recovery_mail', 0);
    }

    public function test_new_staff_enrolls_mfa_without_email_verification_and_can_use_both_login_aliases(): void
    {
        $this->operator();
        $input = $this->input();
        $id = $this->browser('POST', '/api/v1/identity/staff', $input, ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('data.id');
        $this->assertDatabaseCount('identity_recovery_tokens', 0);
        $this->browser('GET', '/api/v1/identity/staff?q=New')->assertJsonPath('data.0.onboarding_pending', true);
        $adminCookies = $this->browserCookies;
        $this->initializeBrowser();
        $before = $this->sessionId();
        $this->browser('POST', '/api/v1/auth/username-login', ['username' => ' AHMED.NEW ', 'password' => $input['password']])
            ->assertAccepted()->assertJsonPath('data.next_step', 'mfa_enrollment');
        self::assertNotSame($before, $this->sessionId());
        $this->browser('GET', '/api/v1/identity/staff')->assertUnauthorized();
        // Passive unauthorized probes during MFA intentionally discard that pending session.
        $this->initializeBrowser();
        $this->browser('POST', '/api/v1/auth/login', ['email' => $input['email'], 'password' => $input['password']])->assertAccepted()->assertJsonPath('data.next_step', 'mfa_enrollment');
        $this->enroll();
        $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.email_verified', false);
        $this->browser('POST', '/api/v1/auth/logout')->assertOk();
        $this->initializeBrowser();
        $this->browser('POST', '/api/v1/auth/login', ['email' => $input['email'], 'password' => $input['password']])
            ->assertAccepted()->assertJsonPath('data.next_step', 'mfa_challenge');
        $this->browserCookies = $adminCookies;
        $this->browser('GET', '/api/v1/identity/staff?q=New')->assertJsonPath('data.0.onboarding_pending', false);
    }

    public function test_username_and_email_share_login_attempt_limit(): void
    {
        $this->operator();
        $input = $this->input();
        $this->browser('POST', '/api/v1/identity/staff', $input, ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->initializeBrowser();
        for ($i = 0; $i < 5; $i++) {
            $this->browser('POST', $i % 2 ? '/api/v1/auth/login' : '/api/v1/auth/username-login',
                [$i % 2 ? 'email' : 'username' => $i % 2 ? $input['email'] : 'ahmed.new', 'password' => 'Wrong-Password-92'])->assertUnauthorized();
        }
        $this->browser('POST', '/api/v1/auth/username-login', ['username' => 'ahmed.new', 'password' => $input['password']])->assertTooManyRequests();
    }

    public function test_unfinished_direct_super_admin_does_not_satisfy_last_administrator_guard(): void
    {
        $actor = $this->operator();
        $this->browser('POST', '/api/v1/identity/staff', [...$this->input(), 'roles' => ['super_admin']], ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->browser('PUT', '/api/v1/identity/staff/'.$actor->id.'/authorization', ['roles' => ['support'], 'enabled' => true], ['If-Match' => '"1"'])
            ->assertConflict()->assertJsonPath('error.reason', 'LAST_ENABLED_SUPER_ADMIN');
    }

    public function test_creation_requires_csrf_recent_password_and_current_session(): void
    {
        $actor = $this->operator();
        $input = $this->input();
        $headers = ['Idempotency-Key' => (string) Str::uuid7()];
        $this->browser('POST', '/api/v1/identity/staff', $input, [...$headers, 'X-XSRF-TOKEN' => 'invalid'])->assertForbidden();
        $this->travel(6)->minutes();
        $this->browser('POST', '/api/v1/identity/staff', $input, $headers)->assertForbidden()->assertJsonPath('error.reason', 'PASSWORD_CONFIRMATION_REQUIRED');
        $this->travelBack();
        DB::table('identity_sessions')->where('user_id', $actor->id)->delete();
        $stale = $this->browser('POST', '/api/v1/identity/staff', $input, $headers)->assertUnauthorized();
        self::assertSame([], $stale->headers->getCookies());
        $this->assertDatabaseCount('identity_staff_creation_keys', 0);
    }

    public function test_enrolled_direct_super_admin_is_usable_without_falsifying_email_ownership(): void
    {
        $actor = $this->operator();
        $input = [...$this->input(), 'roles' => ['super_admin']];
        $id = $this->browser('POST', '/api/v1/identity/staff', $input, ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('data.id');
        $adminCookies = $this->browserCookies;
        $this->initializeBrowser();
        $this->browser('POST', '/api/v1/auth/username-login', ['username' => 'ahmed.new', 'password' => $input['password']])->assertAccepted();
        $this->enroll();
        self::assertNull(User::query()->findOrFail($id)->email_verified_at);
        $this->browserCookies = $adminCookies;
        $this->browser('PUT', '/api/v1/identity/staff/'.$actor->id.'/authorization', ['roles' => ['support'], 'enabled' => true], ['If-Match' => '"1"'])->assertOk();
    }

    public function test_unverified_direct_staff_can_reset_password_and_still_requires_mfa(): void
    {
        $this->operator();
        $input = $this->input();
        $id = $this->browser('POST', '/api/v1/identity/staff', $input, ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('data.id');
        $this->initializeBrowser();
        $this->browser('POST', '/api/v1/auth/password/forgot', ['email' => $input['email']])->assertAccepted();
        $token = $this->deliver($id);
        $password = 'Replacement-Password-782';
        $this->browser('POST', '/api/v1/auth/password/reset', ['token' => $token, 'password' => $password, 'password_confirmation' => $password])->assertOk();
        self::assertNull(User::query()->findOrFail($id)->email_verified_at);
        $this->initializeBrowser();
        $this->browser('POST', '/api/v1/auth/username-login', ['username' => 'ahmed.new', 'password' => $password])->assertAccepted()->assertJsonPath('data.next_step', 'mfa_enrollment');
    }

    public function test_failed_creation_receipt_rolls_back_account_roles_and_audit(): void
    {
        $this->operator();
        $before = DB::table('audit_events')->where('event_type', 'identity.staff_account.created')->count();
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into "identity_staff_creation_keys"')) {
                throw new \RuntimeException('Injected creation receipt failure');
            }
        });
        $this->browser('POST', '/api/v1/identity/staff', $this->input(), ['Idempotency-Key' => (string) Str::uuid7()])->assertStatus(500);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_roles', 1);
        $this->assertDatabaseCount('identity_staff_creation_keys', 0);
        $this->assertDatabaseCount('identity_recovery_mail', 0);
        self::assertSame($before, DB::table('audit_events')->where('event_type', 'identity.staff_account.created')->count());
    }

    #[DataProvider('directRoleAccess')]
    public function test_direct_staff_reaches_only_role_granted_features_without_email_verification(string $role, bool $reports, bool $audit, bool $content): void
    {
        $this->operator();
        $input = [...$this->input(), 'roles' => [$role]];
        $id = $this->browser('POST', '/api/v1/identity/staff', $input, ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('data.id');
        $this->initializeBrowser();
        $login = ['username' => 'ahmed.new', 'password' => $input['password']];
        $this->browser('POST', '/api/v1/auth/username-login', $login)->assertAccepted();
        $this->browser('GET', '/api/v1/admin/reports/dashboard')->assertUnauthorized();
        $this->initializeBrowser();
        $this->browser('POST', '/api/v1/auth/username-login', $login)->assertAccepted();
        $this->enroll();
        Config::set('ai.enabled', true);
        $me = $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.email_verified', false)->assertJsonPath('data.roles', [$role]);
        $capabilities = $me->json('data.capabilities');
        self::assertSame($reports || $role === 'portfolio_editor', in_array('admin.dashboard.view', $capabilities, true));
        self::assertSame($reports, in_array('reports.view', $capabilities, true));
        self::assertSame($audit, in_array('audit.investigate', $capabilities, true));
        $authority = app(RoleAuthority::class);
        $permissions = $authority->permissionsFor($authority->roles($id));
        self::assertSame(in_array('ai.use', $permissions, true), in_array('ai.request', $capabilities, true));
        $staffCapabilities = $this->browser('GET', '/api/v1/identity/capabilities')->assertOk()->json('data.capabilities');
        self::assertSame($reports || $role === 'portfolio_editor', in_array('admin.dashboard.view', $staffCapabilities, true));
        foreach (['dashboard', 'requests', 'projects', 'customers'] as $report) {
            $response = $this->browser('GET', '/api/v1/admin/reports/'.$report)->assertStatus($reports ? 200 : 403);
            self::assertSame([], $response->headers->getCookies());
        }
        $this->browser('GET', '/api/v1/admin/audit-events')->assertStatus($audit ? 200 : 403);
        $this->browser('GET', '/api/v1/admin/public-content/capabilities')->assertOk()->assertJsonPath('data.portfolio_manage', $content || $role === 'portfolio_editor')->assertJsonPath('data.contact_manage', $content);
        $this->browser('GET', '/api/v1/admin/portfolio/projects')->assertStatus($content || $role === 'portfolio_editor' ? 200 : 403);
        $this->browser('GET', '/api/v1/admin/contact-messages')->assertStatus($content ? 200 : 403);
        DB::transaction(function () use ($id, $content, $role): void {
            $identity = app(ReadActiveIdentity::class)->locked($id);
            self::assertFalse($identity->verifiedEmail);
            self::assertTrue($identity->emailPrerequisiteSatisfied);
            self::assertSame($content || $role === 'portfolio_editor', app(PortfolioAuthorityAdapter::class)->lockManager($id));
        });
        self::assertNull(User::query()->findOrFail($id)->email_verified_at);
        $this->assertDatabaseCount('identity_recovery_mail', 0);
    }

    public static function directRoleAccess(): array
    {
        return [['super_admin', true, true, true], ['administrator', true, false, true], ['project_manager', true, false, false],
            ['business_analyst', false, false, false], ['sales', false, false, false], ['reviewer', false, false, false], ['support', false, false, false], ['portfolio_editor', false, false, false]];
    }

    public function test_administrator_can_create_and_reassign_portfolio_editor_without_escalating_grants(): void
    {
        $this->operator(Role::Administrator);
        $input = [...$this->input(), 'roles' => ['portfolio_editor']];
        $id = $this->browser('POST', '/api/v1/identity/staff', $input, ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('data.id');
        $url = '/api/v1/identity/staff/'.$id.'/authorization';
        $this->browser('PUT', $url, ['roles' => ['portfolio_editor', 'support'], 'enabled' => true], ['If-Match' => '"1"'])->assertOk();
        $this->browser('PUT', $url, ['roles' => ['super_admin'], 'enabled' => true], ['If-Match' => '"2"'])->assertForbidden();
        $this->browser('PUT', $url, ['roles' => ['project_manager'], 'enabled' => true], ['If-Match' => '"2"'])->assertForbidden();
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

    private function input(): array
    {
        return ['username' => ' Ahmed.New ', 'full_name' => 'New Staff', 'email' => Str::uuid7().'@example.test',
            'password' => 'Correct-Horse-72-River', 'password_confirmation' => 'Correct-Horse-72-River', 'roles' => ['support']];
    }

    private function deliver(string $userId): string
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
        app(OperationRunner::class)->run(DB::table('identity_recovery_mail')->where('user_id', $userId)->value('operation_id'));
        self::assertNotNull($transport->message);
        self::assertSame(1, preg_match('~/admin/reset-password#token=([a-f0-9]{64})~', $transport->message->body, $matches));

        return $matches[1];
    }
}
