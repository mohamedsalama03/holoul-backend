<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Mfa\MfaActions;
use App\Modules\Identity\Mfa\MfaCredential;
use App\Modules\Identity\Models\IdentitySession;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\SessionSecurity;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

final class MfaTest extends TestCase
{
    use DatabaseMigrations;

    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $session = new Store('mfa-test', new ArraySessionHandler(120));
        $session->start();
        $this->request = Request::create('https://localhost/api/v1/auth/mfa');
        $this->request->setLaravelSession($session);
        $this->request->attributes->set('request_id', (string) Str::uuid7());
        app()->instance('request', $this->request);
        app()->instance('session.store', $session);
        Auth::forgetGuards();
        $this->request->setUserResolver(fn () => Auth::guard('web')->user());
    }

    public function test_enrollment_encrypts_pending_secret_and_requires_confirmation_before_login(): void
    {
        $user = $this->staff();
        $this->pending($user);
        $result = app(MfaActions::class)->beginEnrollment($this->request);
        $credential = MfaCredential::query()->sole();

        self::assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $result['secret']);
        self::assertSame($result['secret'], $credential->pending_secret);
        self::assertStringContainsString('issuer=HOLOUL', $result['otpauth_uri']);
        self::assertNull($credential->confirmed_at);
        self::assertNull($credential->secret);
        self::assertNotSame($result['secret'], DB::table('identity_mfa')->value('pending_secret'));
        self::assertArrayNotHasKey('pending_secret', $credential->toArray());
        self::assertFalse(Auth::guard('web')->check());
        $this->assertDatabaseCount('identity_sessions', 0);
        $this->assertDatabaseCount('identity_mfa_recovery_codes', 0);
    }

    public function test_confirmation_stores_only_hashed_recovery_codes_and_rotates_session(): void
    {
        $user = $this->staff();
        $this->pending($user);
        $oldSession = $this->request->session()->getId();
        $oldVersion = $user->auth_version;
        $result = app(MfaActions::class)->beginEnrollment($this->request);
        $codes = app(MfaActions::class)->confirmEnrollment($this->request, $this->otp($result['secret']));
        $credential = MfaCredential::query()->sole();

        self::assertCount(10, array_unique($codes));
        self::assertNotSame($oldSession, $this->request->session()->getId());
        self::assertSame($oldVersion + 1, $user->refresh()->auth_version);
        self::assertTrue($this->request->session()->get('identity.mfa_verified'));
        self::assertFalse($this->request->session()->has('identity.pending_user_id'));
        self::assertSame($result['secret'], $credential->secret);
        self::assertNull($credential->pending_secret);
        self::assertNotNull($credential->confirmed_at);
        self::assertNotSame($result['secret'], DB::table('identity_mfa')->value('secret'));
        self::assertArrayNotHasKey('secret', $credential->toArray());
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/\A[0-9a-f]{8}(?:-[0-9a-f]{8}){3}\z/', $code);
            $this->assertDatabaseHas('identity_mfa_recovery_codes', ['code_hash' => hash('sha256', str_replace('-', '', $code))]);
        }
        $audit = json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($result['secret'], $audit);
        self::assertStringNotContainsString($codes[0], $audit);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.mfa.enrollment_confirmed']);
    }

    public function test_invalid_confirmation_is_audited_without_authenticating(): void
    {
        $this->pending($this->staff());
        app(MfaActions::class)->beginEnrollment($this->request);
        $this->rejected(fn () => app(MfaActions::class)->confirmEnrollment($this->request, 'not-a-code'), ValidationException::class);
        self::assertNull(MfaCredential::query()->sole()->confirmed_at);
        self::assertFalse(Auth::guard('web')->check());
        $event = DB::table('audit_events')->where('event_type', 'identity.mfa.enrollment_confirmed')->sole();
        self::assertSame('failed', json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR)['outcome']);
    }

    public function test_expired_enrollment_secret_cannot_be_confirmed(): void
    {
        $this->pending($this->staff());
        $result = app(MfaActions::class)->beginEnrollment($this->request);
        MfaCredential::query()->update(['pending_expires_at' => now()->subSecond()]);
        $this->rejected(fn () => app(MfaActions::class)->confirmEnrollment($this->request, $this->otp($result['secret'])), ValidationException::class);
        self::assertNull(MfaCredential::query()->sole()->confirmed_at);
    }

    public function test_pending_password_session_cannot_replace_an_existing_factor(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->pending($user);
        $this->rejected(fn () => app(MfaActions::class)->beginEnrollment($this->request), HttpException::class);
        $this->rejected(fn () => app(MfaActions::class)->confirmEnrollment($this->request, $this->otp($secret)), HttpException::class);
        self::assertSame($secret, MfaCredential::query()->sole()->secret);
    }

    public function test_pending_session_expiry_future_timestamp_and_version_mismatch_fail_closed(): void
    {
        $user = $this->staff();
        foreach ([time() - 600, time() + 60] as $timestamp) {
            $this->pending($user);
            $this->request->session()->put('identity.pending_started_at', $timestamp);
            $this->rejected(fn () => app(MfaActions::class)->beginEnrollment($this->request), AuthenticationException::class);
        }
        $this->pending($user);
        $this->request->session()->put('identity.pending_auth_version', $user->auth_version + 1);
        $this->rejected(fn () => app(MfaActions::class)->beginEnrollment($this->request), AuthenticationException::class);
        $this->assertDatabaseCount('identity_mfa', 0);
    }

    public function test_disabled_staff_cannot_enroll_or_complete_a_challenge(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->pending($user);
        User::query()->whereKey($user->id)->update(['enabled' => false]);
        $this->rejected(fn () => app(MfaActions::class)->challenge($this->request, $this->otp($secret, 1)), AuthenticationException::class);
        $this->rejected(fn () => app(MfaActions::class)->beginEnrollment($this->request), AuthenticationException::class);
    }

    public function test_customer_is_not_eligible_for_staff_mfa_even_with_pending_keys(): void
    {
        $user = $this->staff();
        $user->kind = 'customer';
        $user->save();
        $this->pending($user);
        $this->rejected(fn () => app(MfaActions::class)->beginEnrollment($this->request), AuthorizationException::class);
        $this->assertDatabaseCount('identity_mfa', 0);
    }

    public function test_database_rejects_a_customer_mfa_credential(): void
    {
        $user = $this->staff();
        $user->kind = 'customer';
        $user->save();
        $this->expectException(QueryException::class);
        MfaCredential::query()->create(['user_id' => $user->id]);
    }

    public function test_challenge_rejects_confirmation_replay_then_accepts_next_step_once(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->pending($user);
        $this->rejected(fn () => app(MfaActions::class)->challenge($this->request, $this->otp($secret)), ValidationException::class);
        $nextCode = $this->otp($secret, 1);
        app(MfaActions::class)->challenge($this->request, $nextCode);
        self::assertTrue($this->request->session()->get('identity.mfa_verified'));
        $this->pending($user);
        $this->rejected(fn () => app(MfaActions::class)->challenge($this->request, $nextCode), ValidationException::class);
        self::assertFalse(Auth::guard('web')->check());
    }

    public function test_totp_rejects_codes_outside_clock_window_and_non_ascii_or_long_codes(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->pending($user);
        foreach ([$this->otp($secret, 3), '１２３４５６', '1234567', '1e0000', ' 123456', '123-456'] as $code) {
            $this->rejected(fn () => app(MfaActions::class)->challenge($this->request, $code), ValidationException::class);
        }
        self::assertFalse(Auth::guard('web')->check());
    }

    public function test_recovery_code_is_consumed_exactly_once(): void
    {
        [$user, , $codes] = $this->enrolled();
        $this->pending($user);
        app(MfaActions::class)->recover($this->request, strtoupper($codes[0]));
        self::assertTrue($this->request->session()->get('identity.mfa_verified'));
        self::assertSame(1, DB::table('identity_mfa_recovery_codes')->whereNotNull('consumed_at')->count());
        $this->pending($user);
        $this->rejected(fn () => app(MfaActions::class)->recover($this->request, $codes[0]), ValidationException::class);
        self::assertFalse(Auth::guard('web')->check());
        self::assertSame(1, DB::table('identity_mfa_recovery_codes')->whereNotNull('consumed_at')->count());
    }

    public function test_invalid_recovery_does_not_consume_any_code(): void
    {
        [$user] = $this->enrolled();
        $this->pending($user);
        $this->rejected(fn () => app(MfaActions::class)->recover($this->request, str_repeat('0', 32)), ValidationException::class);
        self::assertSame(0, DB::table('identity_mfa_recovery_codes')->whereNotNull('consumed_at')->count());
    }

    public function test_regeneration_requires_recent_password_and_replaces_all_old_codes(): void
    {
        [$user, , $oldCodes] = $this->enrolled();
        $this->request->session()->put('identity.password_confirmed_at', time() - 301);
        $this->rejected(fn () => app(MfaActions::class)->regenerateRecoveryCodes($this->request), HttpException::class);
        $this->request->session()->put('identity.password_confirmed_at', time());
        $version = $user->auth_version;
        $newCodes = app(MfaActions::class)->regenerateRecoveryCodes($this->request);
        self::assertCount(10, $newCodes);
        self::assertSame([], array_intersect($oldCodes, $newCodes));
        self::assertSame($version + 1, $user->refresh()->auth_version);
        $this->assertDatabaseCount('identity_mfa_recovery_codes', 10);
        $this->assertDatabaseCount('identity_sessions', 1);
        $this->pending($user);
        $this->rejected(fn () => app(MfaActions::class)->recover($this->request, $oldCodes[0]), ValidationException::class);
        app(MfaActions::class)->recover($this->request, $newCodes[0]);
    }

    public function test_disable_requires_recent_password_revokes_sessions_and_forces_new_enrollment(): void
    {
        [$user, , $codes] = $this->enrolled();
        $this->request->session()->put('identity.password_confirmed_at', time() + 60);
        $this->rejected(fn () => app(MfaActions::class)->disable($this->request), HttpException::class);
        $this->request->session()->put('identity.password_confirmed_at', time());
        $version = $user->auth_version;
        app(MfaActions::class)->disable($this->request);
        self::assertSame($version + 1, $user->refresh()->auth_version);
        self::assertFalse(Auth::guard('web')->check());
        self::assertFalse($this->request->session()->has('identity.mfa_verified'));
        self::assertNull(MfaCredential::query()->sole()->secret);
        self::assertNull(MfaCredential::query()->sole()->confirmed_at);
        $this->assertDatabaseCount('identity_sessions', 0);
        $this->assertDatabaseCount('identity_mfa_recovery_codes', 0);
        $this->pending($user);
        $this->rejected(fn () => app(MfaActions::class)->recover($this->request, $codes[0]), HttpException::class);
        self::assertArrayHasKey('secret', app(MfaActions::class)->beginEnrollment($this->request));
    }

    public function test_pending_or_expired_full_session_cannot_disable_or_regenerate(): void
    {
        [$user] = $this->enrolled();
        IdentitySession::query()->where('user_id', $user->id)->update([
            'authenticated_at' => now()->subHour(), 'last_activity_at' => now()->subHour(), 'expires_at' => now()->subSecond(),
        ]);
        $this->rejected(fn () => app(MfaActions::class)->regenerateRecoveryCodes($this->request), AuthenticationException::class);
        $this->pending($user);
        $this->rejected(fn () => app(MfaActions::class)->disable($this->request), AuthenticationException::class);
        self::assertNotNull(MfaCredential::query()->sole()->confirmed_at);
    }

    public function test_mfa_completion_does_not_refresh_the_actual_password_confirmation_time(): void
    {
        $user = $this->staff();
        $this->pending($user);
        $confirmedAt = time() - 301;
        $this->request->session()->put('identity.password_confirmed_at', $confirmedAt);
        $result = app(MfaActions::class)->beginEnrollment($this->request);
        app(MfaActions::class)->confirmEnrollment($this->request, $this->otp($result['secret']));
        self::assertSame($confirmedAt, $this->request->session()->get('identity.password_confirmed_at'));
        $this->rejected(fn () => app(MfaActions::class)->disable($this->request), HttpException::class);
    }

    public function test_audit_failure_rolls_back_recovery_consumption_and_authentication(): void
    {
        [$user, , $codes] = $this->enrolled();
        $this->pending($user);
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT mfa_test_fail CHECK (event_type <> 'identity.mfa.recovery_used')");
        try {
            $this->rejected(fn () => app(MfaActions::class)->recover($this->request, $codes[0]), QueryException::class);
            self::assertSame(0, DB::table('identity_mfa_recovery_codes')->whereNotNull('consumed_at')->count());
            self::assertFalse(Auth::guard('web')->check());
        } finally {
            DB::statement('ALTER TABLE audit_events DROP CONSTRAINT mfa_test_fail');
        }
    }

    public function test_database_rejects_confirmed_credentials_without_replay_state(): void
    {
        $user = $this->staff();
        $this->expectException(QueryException::class);
        MfaCredential::query()->create(['user_id' => $user->id, 'secret' => 'TESTSECRET', 'confirmed_at' => now()]);
    }

    /** @return array{User, string, list<string>} */
    private function enrolled(): array
    {
        $user = $this->staff();
        $this->pending($user);
        $result = app(MfaActions::class)->beginEnrollment($this->request);
        $codes = app(MfaActions::class)->confirmEnrollment($this->request, $this->otp($result['secret']));

        return [$user->refresh(), $result['secret'], $codes];
    }

    private function staff(): User
    {
        return User::query()->create([
            'full_name' => 'MFA Staff', 'email' => 'mfa-'.Str::uuid7().'@example.test',
            'email_display' => 'MFA@example.test', 'password' => 'ExamplePassword123!',
            'kind' => 'staff', 'enabled' => true, 'auth_version' => 1,
        ]);
    }

    private function pending(User $user): void
    {
        Auth::forgetGuards();
        app(SessionSecurity::class)->beginStaffLogin($this->request, $user->refresh());
    }

    private function otp(string $secret, int $offset = 0): string
    {
        return (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30) + $offset);
    }

    /** @param class-string<Throwable> $type */
    private function rejected(Closure $action, string $type): void
    {
        try {
            $action();
        } catch (Throwable $exception) {
            self::assertInstanceOf($type, $exception);

            return;
        }
        self::fail('The security action unexpectedly succeeded.');
    }
}
