<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\Identity\Authorization\Actions\AssignCustomerRole;
use App\Modules\Identity\Authorization\Actions\BootstrapSuperAdmin;
use App\Modules\Identity\Authorization\Actions\ChangeStaffAuthorization;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\IdentitySession;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class RoleFoundationTest extends TestCase
{
    use DatabaseMigrations;

    private static ?string $passwordHash = null;

    public function test_catalog_has_exactly_the_candidate_roles_and_only_explicit_approved_permissions(): void
    {
        self::assertEqualsCanonicalizing(array_column(Role::cases(), 'value'), DB::table('roles')->pluck('code')->all());
        self::assertEqualsCanonicalizing(array_column(Permission::cases(), 'value'), DB::table('permissions')->pluck('code')->all());
        $manager = $this->user(Role::ProjectManager);
        $super = $this->user(Role::SuperAdmin);
        $authority = app(RoleAuthority::class);

        self::assertTrue($authority->allows($manager, Permission::ReadOwnIdentity));
        self::assertFalse($authority->allows($manager, Permission::ReadStaff));
        self::assertTrue($authority->allows($super, Permission::ManageSecurity));
        self::assertFalse($authority->allows($super, Permission::ReadOwnCustomer));
        DB::table('users')->where('id', $super->id)->update(['enabled' => false]);
        self::assertFalse($authority->allows($super, Permission::ManageSecurity));
    }

    public function test_customer_role_is_server_assigned_once_and_audited(): void
    {
        $customer = $this->user();
        $requestId = (string) Str::uuid7();
        app(AssignCustomerRole::class)->handle($customer->id, $requestId);
        app(AssignCustomerRole::class)->handle($customer->id, $requestId);

        self::assertSame([Role::Customer], app(RoleAuthority::class)->roles($customer->id));
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.role.customer.granted', 'subject_id' => $customer->id]);
        self::assertSame(1, DB::table('audit_events')->where('event_type', 'identity.role.customer.granted')->count());
    }

    #[DataProvider('mixedPersonas')]
    public function test_database_rejects_mixed_customer_and_staff_roles(string $kind, Role $role): void
    {
        $user = $this->user(kind: $kind);
        $this->expectException(QueryException::class);
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => app(RoleAuthority::class)->roleId($role), 'user_kind' => $kind]);
    }

    public function test_bootstrap_is_one_time_and_never_promotes_a_customer(): void
    {
        $first = $this->user(kind: 'staff');
        app(BootstrapSuperAdmin::class)->handle($first, (string) Str::uuid7());
        self::assertSame([Role::SuperAdmin], app(RoleAuthority::class)->roles($first->id));
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.super_admin.bootstrapped', 'subject_id' => $first->id, 'actor_id' => null]);
        $second = $this->user(kind: 'staff');
        $this->expectException(ConflictHttpException::class);
        app(BootstrapSuperAdmin::class)->handle($second, (string) Str::uuid7());
    }

    public function test_customer_cannot_bootstrap_a_staff_security_role(): void
    {
        $this->expectException(AuthorizationException::class);
        app(BootstrapSuperAdmin::class)->handle($this->user(), (string) Str::uuid7());
    }

    public function test_customer_cannot_self_promote(): void
    {
        $customer = $this->user();
        $request = $this->login($customer);
        $this->expectException(AuthorizationException::class);
        app(ChangeStaffAuthorization::class)->handle($request, $customer->id, [Role::SuperAdmin], true);
    }

    public function test_staff_without_management_permission_cannot_assign_roles(): void
    {
        $support = $this->user(Role::Support);
        $target = $this->user(Role::Reviewer);
        $this->expectException(AuthorizationException::class);
        app(ChangeStaffAuthorization::class)->handle($this->login($support), $target->id, [Role::Administrator], true);
    }

    #[DataProvider('securityRoles')]
    public function test_administrator_cannot_grant_security_roles(Role $role): void
    {
        $administrator = $this->user(Role::Administrator);
        $target = $this->user(Role::Reviewer);
        $this->expectException(AuthorizationException::class);
        app(ChangeStaffAuthorization::class)->handle($this->login($administrator), $target->id, [$role], true);
    }

    public function test_administrator_cannot_modify_an_existing_super_admin(): void
    {
        $administrator = $this->user(Role::Administrator);
        $super = $this->user(Role::SuperAdmin);
        $this->expectException(AuthorizationException::class);
        app(ChangeStaffAuthorization::class)->handle($this->login($administrator), $super->id, [Role::Support], true);
    }

    public function test_super_admin_cannot_mix_staff_and_customer_personas(): void
    {
        $super = $this->user(Role::SuperAdmin);
        $target = $this->user(Role::Reviewer);
        $this->expectException(AuthorizationException::class);
        app(ChangeStaffAuthorization::class)->handle($this->login($super), $target->id, [Role::Customer], true);
    }

    public function test_role_change_requires_recent_password_confirmation(): void
    {
        $super = $this->user(Role::SuperAdmin);
        $target = $this->user(Role::Reviewer);
        $request = $this->login($super);
        $request->session()->put('identity.password_confirmed_at', time() - 301);

        try {
            app(ChangeStaffAuthorization::class)->handle($request, $target->id, [Role::Support], true);
            self::fail('Old password confirmation authorized a role change.');
        } catch (HttpExceptionInterface $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    #[DataProvider('invalidSessions')]
    public function test_action_revalidates_mfa_session_expiry_and_revoked_authorization(string $scenario): void
    {
        $super = $this->user(Role::SuperAdmin);
        $target = $this->user(Role::Reviewer);
        $request = $this->login($super);

        if ($scenario === 'mfa') {
            $request->session()->put('identity.mfa_verified', false);
        } elseif ($scenario === 'expired') {
            IdentitySession::query()->where('user_id', $super->id)->update(['authenticated_at' => now()->subHour(), 'expires_at' => now()->subSecond()]);
        } elseif ($scenario === 'disabled') {
            $super->enabled = false;
            $super->save();
        } else {
            app(SessionSecurity::class)->revokeAll($super->id, (string) Str::uuid7());
        }

        $this->expectException(AuthenticationException::class);
        app(ChangeStaffAuthorization::class)->handle($request, $target->id, [Role::Support], true);
    }

    #[DataProvider('lastAdminChanges')]
    public function test_last_enabled_super_admin_cannot_be_demoted_or_disabled(array $roles, bool $enabled): void
    {
        $super = $this->user(Role::SuperAdmin);
        $request = $this->login($super);
        $this->expectException(ConflictHttpException::class);
        app(ChangeStaffAuthorization::class)->handle($request, $super->id, $roles, $enabled);
    }

    public function test_authorized_role_change_revokes_target_sessions_and_records_only_safe_audit_events(): void
    {
        $super = $this->user(Role::SuperAdmin);
        $target = $this->user(Role::Reviewer);
        $this->sessionRecord($target);
        $request = $this->login($super);
        $record = app(ChangeStaffAuthorization::class)->handle($request, $target->id, [Role::Support], true);

        self::assertSame(['support'], $record->roles);
        self::assertSame(2, $target->fresh()->auth_version);
        self::assertSame(0, IdentitySession::query()->where('user_id', $target->id)->count());
        self::assertSame(1, IdentitySession::query()->where('user_id', $super->id)->count());
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.role.support.granted', 'subject_id' => $target->id, 'actor_id' => $super->id, 'metadata' => '{}']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.role.reviewer.revoked', 'subject_id' => $target->id, 'actor_id' => $super->id, 'metadata' => '{}']);
    }

    public function test_failed_audit_rolls_back_roles_status_and_auth_version(): void
    {
        $super = $this->user(Role::SuperAdmin);
        $target = $this->user(Role::Reviewer);
        $request = $this->login($super);
        $request->attributes->set('request_id', 'invalid-request-id');

        try {
            app(ChangeStaffAuthorization::class)->handle($request, $target->id, [Role::Support], false);
            self::fail('Invalid audit context committed a security change.');
        } catch (InvalidArgumentException) {
            self::assertSame([Role::Reviewer], app(RoleAuthority::class)->roles($target->id));
            self::assertTrue($target->fresh()->enabled);
            self::assertSame(1, $target->fresh()->auth_version);
        }
    }

    public function test_bootstrap_command_refuses_noninteractive_secret_input_without_creating_an_account(): void
    {
        $this->artisan('identity:bootstrap-super-admin', ['--name' => 'Initial Operator', '--email' => 'operator@example.test', '--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_bootstrap_treats_a_hash_looking_password_as_literal_input(): void
    {
        Queue::fake();
        $literal = Hash::make('weak');
        $this->artisan('identity:bootstrap-super-admin', ['--name' => 'Initial Operator', '--email' => 'operator@example.test'])
            ->expectsQuestion('Password', $literal)
            ->expectsQuestion('Confirm password', $literal)
            ->assertSuccessful();

        $user = User::query()->sole();
        self::assertNotSame($literal, $user->password);
        self::assertTrue(Hash::check($literal, $user->password));
        self::assertFalse(Hash::check('weak', $user->password));
        self::assertNull($user->email_verified_at);
        self::assertSame([Role::SuperAdmin], app(RoleAuthority::class)->roles($user->id));
        self::assertStringNotContainsString($literal, json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR));
    }

    private function login(User $user): Request
    {
        $request = Request::create('/api/v1/identity/staff/'.$user->id.'/authorization', 'PATCH');
        $request->setLaravelSession(app('session.store'));
        $request->attributes->set('request_id', (string) Str::uuid7());
        $request->session()->start();
        app(SessionSecurity::class)->completeLogin($request, $user, $user->kind === 'staff');
        $request->setUserResolver(fn (): User => $user);

        return $request;
    }

    private function user(?Role $role = null, string $kind = 'customer'): User
    {
        self::$passwordHash ??= Hash::make('LongTestPassword42!');
        $email = Str::uuid7().'@example.test';
        $kind = $role !== null && $role !== Role::Customer ? 'staff' : $kind;
        $user = User::query()->create(['full_name' => 'Test Person', 'email' => $email, 'email_display' => $email, 'password' => self::$passwordHash, 'kind' => $kind, 'enabled' => true, 'auth_version' => 1]);

        if ($role !== null) {
            DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => app(RoleAuthority::class)->roleId($role), 'user_kind' => $kind]);
        }

        return $user;
    }

    private function sessionRecord(User $user): void
    {
        IdentitySession::query()->create(['user_id' => $user->id, 'session_hash' => hash('sha256', (string) Str::uuid7()), 'auth_version' => 1, 'authenticated_at' => now(), 'last_activity_at' => now(), 'expires_at' => now()->addHour()]);
    }

    /** @return array<string,array{string,Role}> */
    public static function mixedPersonas(): array
    {
        return ['customer receiving staff role' => ['customer', Role::SuperAdmin], 'staff receiving customer role' => ['staff', Role::Customer]];
    }

    /** @return array<string,array{Role}> */
    public static function securityRoles(): array
    {
        return ['super admin' => [Role::SuperAdmin], 'administrator' => [Role::Administrator]];
    }

    /** @return array<string,array{string}> */
    public static function invalidSessions(): array
    {
        return ['mfa missing' => ['mfa'], 'expired' => ['expired'], 'disabled' => ['disabled'], 'revoked' => ['revoked']];
    }

    /** @return array<string,array{list<Role>,bool}> */
    public static function lastAdminChanges(): array
    {
        return ['demote' => [[Role::Support], true], 'disable' => [[Role::SuperAdmin], false]];
    }
}
