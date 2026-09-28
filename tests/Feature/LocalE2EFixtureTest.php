<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Mfa\MfaCredential;
use App\Modules\Identity\Models\User;
use HoloulLocalE2E\Fixture;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

require_once __DIR__.'/../../tools/local-e2e/Fixture.php';

final class LocalE2EFixtureTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;

    public function test_cli_guard_fails_closed_outside_explicit_local_environment(): void
    {
        $originalEnv = getenv('APP_ENV');
        $originalOptIn = getenv('HOLOUL_LOCAL_E2E');
        $originalConfig = Config::all();
        try {
            putenv('APP_ENV=local');
            putenv('HOLOUL_LOCAL_E2E=1');
            app()->instance('env', 'local');
            $settings = ['app.debug' => false, 'operations.deployment_profile' => 'local-verification',
                'app.url' => 'https://localhost:8443', 'identity.origin' => 'https://localhost:8443',
                'identity.mail_sandbox' => true, 'mail.mailers.smtp.host' => 'mailpit',
                'database.connections.pgsql.host' => 'postgres', 'database.connections.pgsql.database' => 'holoul',
                'database.connections.pgsql.username' => 'holoul_app'];
            Config::set($settings);
            Fixture::assertLocalEnvironment();
            foreach (['production', 'staging', 'testing'] as $env) {
                app()->instance('env', $env);
                $this->refused(Fixture::assertLocalEnvironment(...));
            }
            app()->instance('env', 'local');
            putenv('APP_ENV=production');
            $this->refused(Fixture::assertLocalEnvironment(...));
            putenv('APP_ENV=local');
            putenv('HOLOUL_LOCAL_E2E=0');
            $this->refused(Fixture::assertLocalEnvironment(...));
            putenv('HOLOUL_LOCAL_E2E=1');
            foreach (['app.debug' => true, 'operations.deployment_profile' => 'production',
                'app.url' => 'https://example.com', 'identity.origin' => 'https://localhost:9443',
                'identity.mail_sandbox' => false, 'mail.mailers.smtp.host' => 'external.test',
                'database.connections.pgsql.host' => 'external.test', 'database.connections.pgsql.database' => 'production',
                'database.connections.pgsql.username' => 'holoul_migrator'] as $key => $invalid) {
                Config::set($key, $invalid);
                $this->refused(Fixture::assertLocalEnvironment(...));
                Config::set($key, $settings[$key]);
            }
        } finally {
            foreach ($originalConfig as $key => $value) {
                Config::set($key, $value);
            }
            app()->instance('env', 'testing');
            putenv($originalEnv === false ? 'APP_ENV' : 'APP_ENV='.$originalEnv);
            putenv($originalOptIn === false ? 'HOLOUL_LOCAL_E2E' : 'HOLOUL_LOCAL_E2E='.$originalOptIn);
        }
    }

    public function test_real_fixture_authentication_enrollment_customer_isolation_and_repeatable_reset(): void
    {
        $manifest = $this->manifest();
        $values = (new Fixture)->reset($manifest);
        $ids = DB::table('users')->orderBy('email')->pluck('id')->all();
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('identity_sessions', 0);
        $this->assertDatabaseCount('identity_mfa', 3);
        $this->assertDatabaseCount('identity_mfa_recovery_codes', 30);
        foreach (['STAFF', 'SUPER_ADMIN', 'ADMIN'] as $label) {
            $userId = User::query()->where('email', $values['HOLOUL_E2E_'.$label.'_EMAIL'])->sole()->id;
            self::assertNotSame($values['HOLOUL_E2E_'.$label.'_TOTP_SECRET'], DB::table('identity_mfa')->where('user_id', $userId)->sole()->secret);
        }
        $audit = json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR);
        foreach ([...array_values($manifest['passwords']), $values['HOLOUL_E2E_STAFF_TOTP_SECRET'], $values['HOLOUL_E2E_SUPER_ADMIN_TOTP_SECRET'], $values['HOLOUL_E2E_ADMIN_TOTP_SECRET']] as $secret) {
            self::assertStringNotContainsString($secret, $audit);
        }

        $this->initializeBrowser();
        $this->login($values, 'STAFF')->assertAccepted()->assertJsonPath('data.next_step', 'mfa_challenge');
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['code' => $this->otp($values['HOLOUL_E2E_STAFF_TOTP_SECRET'])])->assertOk();
        $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.mfa_satisfied', true);
        $staffCookies = $this->browserCookies;

        $this->initializeBrowser();
        $this->login($values, 'ENROLL_STAFF')->assertAccepted()->assertJsonPath('data.next_step', 'mfa_enrollment');
        $enrollment = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => $this->otp($enrollment)])
            ->assertOk()->assertJsonCount(10, 'data.recovery_codes');
        $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.kind', 'staff');

        $this->initializeBrowser();
        $this->login($values, 'CUSTOMER')->assertOk()->assertJsonPath('data.next_step', 'authenticated');
        $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.kind', 'customer');
        $this->browser('GET', '/api/v1/admin/customers')->assertForbidden();

        $reset = (new Fixture)->reset($manifest);
        self::assertSame($ids, DB::table('users')->orderBy('email')->pluck('id')->all());
        self::assertNotSame($values['HOLOUL_E2E_STAFF_TOTP_SECRET'], $reset['HOLOUL_E2E_STAFF_TOTP_SECRET']);
        $this->assertDatabaseCount('identity_mfa', 3);
        $this->assertDatabaseCount('identity_sessions', 0);
        $this->browserCookies = $staffCookies;
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        $this->initializeBrowser();
        $this->login($reset, 'ENROLL_STAFF')->assertAccepted()->assertJsonPath('data.next_step', 'mfa_enrollment');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk();
        self::assertNull(MfaCredential::query()->where('user_id', User::query()->where('email', $reset['HOLOUL_E2E_ENROLL_STAFF_EMAIL'])->sole()->id)->sole()->confirmed_at);
    }

    public function test_fixture_admins_use_real_permissions_and_reset_restores_only_the_fixed_identities(): void
    {
        Queue::fake();
        $manifest = $this->manifest();
        $values = (new Fixture)->reset($manifest);
        $this->fixtureMfa($values, 'SUPER_ADMIN');
        $super = User::query()->where('email', $values['HOLOUL_E2E_SUPER_ADMIN_EMAIL'])->sole();
        $staff = User::query()->where('email', $values['HOLOUL_E2E_STAFF_EMAIL'])->sole();
        $roles = array_values(array_filter(Role::cases(), fn (Role $role): bool => $role !== Role::Customer));
        $capabilities = $this->browser('GET', '/api/v1/identity/capabilities')->assertOk();
        self::assertEqualsCanonicalizing(array_map(fn (Role $role): string => $role->value, $roles), $capabilities->json('data.assignable_roles'));
        $directory = $this->browser('GET', '/api/v1/identity/staff')->assertOk()->assertJsonPath('meta.total', 4);
        self::assertSame([], $directory->headers->getCookies());
        foreach ($roles as $role) {
            $this->browser('POST', '/api/v1/identity/staff/invitations',
                ['email' => 'e2e-invitee-'.Str::uuid7().'@example.test', 'full_name' => 'Synthetic Invitee', 'roles' => [$role->value]],
                ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        }
        $url = '/api/v1/identity/staff/'.$staff->id.'/authorization';
        $snapshot = $this->browser('GET', $url)->assertOk();
        $this->browser('PUT', $url, ['roles' => ['reviewer'], 'enabled' => false], ['If-Match' => $snapshot->headers->get('ETag')])->assertOk();
        $superUrl = '/api/v1/identity/staff/'.$super->id.'/authorization';
        $snapshot = $this->browser('GET', $superUrl)->assertOk();
        $this->browser('PUT', $superUrl, ['roles' => ['super_admin'], 'enabled' => false], ['If-Match' => $snapshot->headers->get('ETag')])
            ->assertConflict()->assertJsonPath('error.reason', 'LAST_ENABLED_SUPER_ADMIN');

        $this->fixtureMfa($values, 'ADMIN');
        self::assertSame(['support'], $this->browser('GET', '/api/v1/identity/capabilities')->assertOk()->json('data.assignable_roles'));
        $this->browser('GET', '/api/v1/identity/staff')->assertOk();
        $this->browser('POST', '/api/v1/identity/staff/invitations',
            ['email' => 'e2e-invitee-'.Str::uuid7().'@example.test', 'full_name' => 'Synthetic Support', 'roles' => ['support']],
            ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        foreach (['super_admin' => 'SECURITY_ROLE_RESTRICTED', 'reviewer' => 'ROLE_AUTHORITY_EXCEEDED'] as $role => $reason) {
            $this->browser('POST', '/api/v1/identity/staff/invitations',
                ['email' => 'e2e-invitee-'.Str::uuid7().'@example.test', 'full_name' => 'Refused Invitee', 'roles' => [$role]],
                ['Idempotency-Key' => (string) Str::uuid7()])->assertForbidden()->assertJsonPath('error.reason', $reason);
        }
        $category = $this->browser('POST', '/api/v1/admin/categories',
            ['name' => 'Synthetic E2E category', 'slug' => 'e2e-category-'.Str::uuid7(), 'active' => false, 'display_order' => 0])->assertCreated();
        $this->browser('GET', '/api/v1/admin/categories')->assertOk();
        $cookies = $this->browserCookies;
        $beforeInvitations = DB::table('identity_staff_invitations')->orderBy('id')->get()->toJson();
        $beforeCategories = DB::table('categories')->orderBy('id')->get()->toJson();
        $revision = $staff->refresh()->authorization_revision;
        $reset = (new Fixture)->reset($manifest);
        self::assertTrue($staff->refresh()->enabled);
        self::assertSame([Role::ProjectManager], app(RoleAuthority::class)->roles($staff->id));
        self::assertGreaterThan($revision, $staff->authorization_revision);
        self::assertSame($beforeInvitations, DB::table('identity_staff_invitations')->orderBy('id')->get()->toJson());
        self::assertSame($beforeCategories, DB::table('categories')->orderBy('id')->get()->toJson());
        foreach (['SUPER_ADMIN', 'ADMIN'] as $label) {
            self::assertNotSame($values['HOLOUL_E2E_'.$label.'_TOTP_SECRET'], $reset['HOLOUL_E2E_'.$label.'_TOTP_SECRET']);
        }
        $this->browserCookies = $cookies;
        $denied = $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        self::assertSame([], $denied->headers->getCookies());
        $this->assertDatabaseHas('categories', ['id' => $category->json('data.id'), 'active' => false]);
        $this->assertDatabaseCount('users', 5);
    }

    private function fixtureMfa(array $values, string $label): void
    {
        $this->initializeBrowser();
        $this->login($values, $label)->assertAccepted()->assertJsonPath('data.next_step', 'mfa_challenge');
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['code' => $this->otp($values['HOLOUL_E2E_'.$label.'_TOTP_SECRET'])])->assertOk();
    }

    public function test_fixture_collision_rolls_back_and_cannot_reset_an_unowned_identity(): void
    {
        $manifest = $this->manifest();
        $values = (new Fixture)->reset($manifest);
        $user = User::query()->where('email', $values['HOLOUL_E2E_STAFF_EMAIL'])->sole();
        $user->full_name = 'Not owned by fixture';
        $user->save();
        $before = $user->refresh()->getRawOriginal();
        $this->refused(fn () => (new Fixture)->reset($manifest));
        self::assertSame($before, $user->refresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 5);
    }

    public function test_configuration_rejects_arbitrary_identities_and_shared_or_weak_passwords(): void
    {
        $manifest = $this->manifest();
        foreach ([['run' => '../production', 'passwords' => $manifest['passwords']],
            [...$manifest, 'email' => 'real@example.com'],
            ['run' => $manifest['run'], 'passwords' => array_fill_keys(array_keys($manifest['passwords']), $manifest['passwords']['staff'])],
            ['run' => $manifest['run'], 'passwords' => [...$manifest['passwords'], 'staff' => 'universal-password']]] as $invalid) {
            $this->refused(fn () => Fixture::manifest(json_encode($invalid, JSON_THROW_ON_ERROR)));
        }
        $this->assertDatabaseCount('users', 0);
    }

    private function manifest(): array
    {
        return Fixture::manifest(json_encode(['run' => bin2hex(random_bytes(12)), 'passwords' => [
            'staff' => 'E2E-'.bin2hex(random_bytes(32)).'!aA9',
            'enroll_staff' => 'E2E-'.bin2hex(random_bytes(32)).'!aA9',
            'customer' => 'E2E-'.bin2hex(random_bytes(32)).'!aA9',
            'super_admin' => 'E2E-'.bin2hex(random_bytes(32)).'!aA9',
            'admin' => 'E2E-'.bin2hex(random_bytes(32)).'!aA9',
        ]], JSON_THROW_ON_ERROR));
    }

    private function login(array $values, string $label): TestResponse
    {
        return $this->browser('POST', '/api/v1/auth/login', ['email' => $values['HOLOUL_E2E_'.$label.'_EMAIL'], 'password' => $values['HOLOUL_E2E_'.$label.'_PASSWORD']]);
    }

    private function otp(string $secret): string
    {
        return (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30));
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe fixture operation was accepted.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
    }
}
