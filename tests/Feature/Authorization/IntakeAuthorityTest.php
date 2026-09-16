<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\Identity\Actions\WithAuthorizedIdentity;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Models\IdentitySession;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\DatabaseAuthorizedStaffReader;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class IntakeAuthorityTest extends TestCase
{
    use DatabaseMigrations;

    private static ?string $passwordHash = null;

    /** @return iterable<string, array{Role,list<string>,bool}> */
    public static function matrix(): iterable
    {
        yield 'super admin explicit access' => [Role::SuperAdmin, ['taxonomy.manage', 'intake.read', 'intake.read_all', 'intake.assign', 'intake.review', 'intake.information', 'intake.discovery', 'intake.reject'], true];
        yield 'administrator taxonomy only' => [Role::Administrator, ['taxonomy.manage'], false];
        yield 'manager scoped intake' => [Role::ProjectManager, ['intake.read', 'intake.assign', 'intake.review', 'intake.information', 'intake.discovery', 'intake.reject'], true];
        yield 'analyst clarification' => [Role::BusinessAnalyst, ['intake.read', 'intake.information'], true];
        yield 'reviewer cannot reject or assign' => [Role::Reviewer, ['intake.read', 'intake.review', 'intake.information', 'intake.discovery'], true];
        yield 'sales no private intake' => [Role::Sales, [], false];
        yield 'support no private intake' => [Role::Support, [], false];
        yield 'customer ownership policies' => [Role::Customer, [], false];
    }

    /** @param list<string> $expected */
    #[DataProvider('matrix')]
    public function test_only_the_explicit_b3_permission_matrix_is_granted(Role $role, array $expected, bool $assignable): void
    {
        $user = $this->user($role);
        $authority = app(RoleAuthority::class);
        $permissions = $authority->permissionsFor($authority->roles($user->id));
        $b3 = array_values(array_filter($permissions, fn (string $code): bool => str_starts_with($code, 'intake.') || $code === 'taxonomy.manage'));
        self::assertEqualsCanonicalizing($expected, $b3);
        self::assertEqualsCanonicalizing(array_column(Permission::cases(), 'value'), DB::table('permissions')->pluck('code')->all());
        DB::transaction(function () use ($user, $assignable, $expected): void {
            $candidate = app(DatabaseAuthorizedStaffReader::class)->forIntakeAssignment($user->id);
            self::assertSame($assignable, $candidate !== null);
            if ($candidate !== null) {
                self::assertSame($user->id, $candidate->id);
                foreach ($expected as $permission) {
                    self::assertTrue($candidate->allows($permission));
                }
            }
        });
    }

    public function test_authorized_context_revalidates_session_and_has_no_model_or_credentials(): void
    {
        $user = $this->user(Role::Reviewer);
        $request = $this->login($user);
        $context = app(WithAuthorizedIdentity::class)->handle($request, function (AuthorizedIdentity $identity): AuthorizedIdentity {
            self::assertSame(1, DB::transactionLevel());

            return $identity;
        });
        self::assertSame($user->id, $context->id);
        self::assertSame('staff', $context->kind);
        self::assertFalse($context->verifiedEmail);
        self::assertTrue($context->allows('intake.review'));
        self::assertFalse($context->allows('intake.reject'));
        self::assertFalse($context->allows('intake.read_all'));
        self::assertSame(['id', 'kind', 'verifiedEmail', 'permissions'], array_keys(get_object_vars($context)));
        self::assertSame(0, DB::transactionLevel());
    }

    /** @return iterable<string, array{string}> */
    public static function staleAuthentication(): iterable
    {
        foreach (['disabled', 'version', 'missing_mfa', 'expired', 'session_deleted', 'wrong_session_hash'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('staleAuthentication')]
    public function test_stale_or_unverified_staff_authentication_never_enters_callback(string $scenario): void
    {
        $user = $this->user(Role::Reviewer);
        $request = $this->login($user);
        if ($scenario === 'disabled') {
            User::query()->whereKey($user->id)->update(['enabled' => false]);
        } elseif ($scenario === 'version') {
            User::query()->whereKey($user->id)->update(['auth_version' => 2]);
        } elseif ($scenario === 'missing_mfa') {
            $request->session()->put('identity.mfa_verified', false);
        } elseif ($scenario === 'expired') {
            IdentitySession::query()->where('user_id', $user->id)->update(['authenticated_at' => now()->subHour(), 'expires_at' => now()->subSecond()]);
        } elseif ($scenario === 'session_deleted') {
            IdentitySession::query()->where('user_id', $user->id)->delete();
        } else {
            IdentitySession::query()->where('user_id', $user->id)->update(['session_hash' => hash('sha256', 'different-session')]);
        }
        $this->expectException(AuthenticationException::class);
        app(WithAuthorizedIdentity::class)->handle($request, function (): void {
            self::fail('An invalid persisted session reached the business workflow.');
        });
    }

    public function test_callback_rollback_is_atomic_with_authorized_database_work(): void
    {
        $user = $this->user(Role::Reviewer);
        $request = $this->login($user);
        try {
            app(WithAuthorizedIdentity::class)->handle($request, function (AuthorizedIdentity $identity): void {
                DB::table('users')->where('id', $identity->id)->update(['full_name' => 'Rolled back']);
                throw new RuntimeException('test_callback_failure');
            });
            self::fail('Expected the callback to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('test_callback_failure', $exception->getMessage());
        }
        self::assertSame('Authority Test', $user->refresh()->full_name);
    }

    public function test_user_and_active_session_remain_locked_for_the_entire_callback(): void
    {
        $user = $this->user(Role::Reviewer);
        $request = $this->login($user);
        Config::set('database.connections.intake_auth_peer', Config::array('database.connections.'.DB::getDefaultConnection()));
        $peer = DB::connection('intake_auth_peer');
        $peer->statement("SET lock_timeout = '100ms'");
        try {
            app(WithAuthorizedIdentity::class)->handle($request, function (AuthorizedIdentity $identity) use ($peer): void {
                foreach ([
                    fn () => $peer->table('users')->where('id', $identity->id)->update(['enabled' => false]),
                    fn () => $peer->table('users')->where('id', $identity->id)->increment('auth_version'),
                    fn () => $peer->table('identity_sessions')->where('user_id', $identity->id)->delete(),
                ] as $mutation) {
                    try {
                        $mutation();
                        self::fail('A security mutation bypassed a held authorization lock.');
                    } catch (QueryException $exception) {
                        self::assertSame('55P03', $exception->getCode());
                    }
                }
            });
            self::assertSame(1, $peer->table('users')->where('id', $user->id)->update(['enabled' => false]));
        } finally {
            DB::purge('intake_auth_peer');
        }
    }

    public function test_candidate_lookup_rejects_missing_disabled_malformed_and_unlocked_contexts(): void
    {
        $candidate = $this->user(Role::Reviewer);
        $candidate->enabled = false;
        $candidate->save();
        DB::transaction(function () use ($candidate): void {
            $reader = app(DatabaseAuthorizedStaffReader::class);
            self::assertNull($reader->forIntakeAssignment($candidate->id));
            self::assertNull($reader->forIntakeAssignment((string) Str::uuid7()));
            self::assertNull($reader->forIntakeAssignment('malformed'));
        });
        $this->expectException(LogicException::class);
        app(DatabaseAuthorizedStaffReader::class)->forIntakeAssignment($candidate->id);
    }

    public function test_context_reads_current_permissions_and_a_read_only_candidate_is_ineligible(): void
    {
        $reviewer = $this->user(Role::Reviewer);
        $request = $this->login($reviewer);
        $reviewerRole = app(RoleAuthority::class)->roleId(Role::Reviewer);
        $readId = DB::table('permissions')->where('code', 'intake.read')->sole()->id;
        DB::table('role_permissions')->where('role_id', $reviewerRole)->where('permission_id', '<>', $readId)->delete();
        app(WithAuthorizedIdentity::class)->handle($request, function (AuthorizedIdentity $identity) use ($reviewer): void {
            self::assertTrue($identity->allows('intake.read'));
            self::assertFalse($identity->allows('intake.review'));
            self::assertNull(app(DatabaseAuthorizedStaffReader::class)->forIntakeAssignment($reviewer->id));
        });
    }

    public function test_assignee_lock_prevents_competing_disablement_before_assignment_commit(): void
    {
        $candidate = $this->user(Role::BusinessAnalyst);
        Config::set('database.connections.intake_assignee_peer', Config::array('database.connections.'.DB::getDefaultConnection()));
        $peer = DB::connection('intake_assignee_peer');
        $peer->statement("SET lock_timeout = '100ms'");
        try {
            DB::transaction(function () use ($candidate, $peer): void {
                self::assertNotNull(app(DatabaseAuthorizedStaffReader::class)->forIntakeAssignment($candidate->id));
                try {
                    $peer->table('users')->where('id', $candidate->id)->update(['enabled' => false]);
                    self::fail('Candidate disablement bypassed the assignment lock.');
                } catch (QueryException $exception) {
                    self::assertSame('55P03', $exception->getCode());
                }
            });
            self::assertSame(1, $peer->table('users')->where('id', $candidate->id)->update(['enabled' => false]));
        } finally {
            DB::purge('intake_assignee_peer');
        }
        DB::transaction(fn () => self::assertNull(app(DatabaseAuthorizedStaffReader::class)->forIntakeAssignment($candidate->id)));
    }

    private function user(Role $role): User
    {
        self::$passwordHash ??= Hash::make('Authorization test password 2026!');
        $email = Str::uuid7().'@example.test';
        $kind = $role === Role::Customer ? 'customer' : 'staff';
        $user = User::query()->create(['full_name' => 'Authority Test', 'email' => $email, 'email_display' => $email,
            'password' => self::$passwordHash, 'kind' => $kind, 'enabled' => true, 'auth_version' => 1]);
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => app(RoleAuthority::class)->roleId($role), 'user_kind' => $kind]);

        return $user;
    }

    private function login(User $user): Request
    {
        $request = Request::create('https://localhost/api/v1/intake');
        $session = new Store('intake-authority', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        $request->attributes->set('request_id', (string) Str::uuid7());
        app()->instance('request', $request);
        app()->instance('session.store', $session);
        Auth::forgetGuards();
        app(SessionSecurity::class)->completeLogin($request, $user, $user->kind === 'staff');

        return $request;
    }
}
