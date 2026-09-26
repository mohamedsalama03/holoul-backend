<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Mfa\MfaCredential;
use App\Modules\Identity\Models\User;
use HoloulLocalE2E\Fixture;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

require_once __DIR__.'/../../tools/local-e2e/Fixture.php';

final class LocalE2EFixtureTest extends TestCase
{
    use DatabaseMigrations;
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
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('identity_sessions', 0);
        $this->assertDatabaseCount('identity_mfa', 1);
        $this->assertDatabaseCount('identity_mfa_recovery_codes', 10);
        self::assertNotSame($values['HOLOUL_E2E_STAFF_TOTP_SECRET'], DB::table('identity_mfa')->sole()->secret);
        $audit = json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR);
        foreach ([...array_values($manifest['passwords']), $values['HOLOUL_E2E_STAFF_TOTP_SECRET']] as $secret) {
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
        $this->assertDatabaseCount('identity_mfa', 1);
        $this->assertDatabaseCount('identity_sessions', 0);
        $this->browserCookies = $staffCookies;
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        $this->initializeBrowser();
        $this->login($reset, 'ENROLL_STAFF')->assertAccepted()->assertJsonPath('data.next_step', 'mfa_enrollment');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk();
        self::assertNull(MfaCredential::query()->where('user_id', User::query()->where('email', $reset['HOLOUL_E2E_ENROLL_STAFF_EMAIL'])->sole()->id)->sole()->confirmed_at);
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
        $this->assertDatabaseCount('users', 3);
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
