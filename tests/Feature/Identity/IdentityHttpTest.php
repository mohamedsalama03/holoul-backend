<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Customers\Actions\CreateCustomer;
use App\Modules\Identity\Models\IdentitySession;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

final class IdentityHttpTest extends TestCase
{
    use DatabaseMigrations;
    use IdentityHttp;

    protected function setUp(): void
    {
        parent::setUp();
        // The test namespace is isolated from runtime Redis and other projects.
        $this->initializeBrowser();
    }

    public function test_registration_commits_profile_role_and_audit_without_verification_mail_or_fabricated_ownership(): void
    {
        $input = $this->registration();
        $this->browser('POST', '/api/v1/auth/register', $input)->assertAccepted();
        $user = User::query()->sole();
        self::assertSame('Ada Lovelace', $user->full_name);
        self::assertSame('ada@example.test', $user->email);
        self::assertTrue(Hash::check($input['password'], $user->password));
        self::assertSame('argon2id', password_get_info($user->password)['algoName']);
        self::assertNull($user->email_verified_at);
        self::assertSame('customer', $user->kind);
        $this->assertDatabaseHas('customers', ['user_id' => $user->id, 'phone_e164' => '+12025550123']);
        $this->assertDatabaseCount('user_roles', 1);
        $this->assertDatabaseCount('identity_recovery_mail', 0);
        $this->assertDatabaseCount('identity_recovery_tokens', 0);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.registered', 'subject_id' => $user->id]);
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
    }

    public function test_duplicate_registration_has_the_same_response_and_never_replaces_credentials(): void
    {
        $input = $this->registration();
        $first = $this->browser('POST', '/api/v1/auth/register', $input)->assertAccepted()->json();
        $input['email'] = 'ada@example.test';
        $input['password'] = $input['password_confirmation'] = 'Different-Password-883';
        $second = $this->browser('POST', '/api/v1/auth/register', $input)->assertAccepted()->json();
        self::assertSame($first, $second);
        $this->assertDatabaseCount('users', 1);
        self::assertFalse(Hash::check($input['password'], User::query()->sole()->password));
    }

    #[DataProvider('forgedFields')]
    public function test_registration_rejects_forged_privilege_and_owner_fields(string $field, mixed $value): void
    {
        $this->browser('POST', '/api/v1/auth/register', [...$this->registration(), $field => $value])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public static function forgedFields(): array
    {
        return [['role', 'Super Admin'], ['roles', ['Super Admin']], ['permission', '*'], ['customer_id', (string) Str::uuid7()],
            ['staff', true], ['enabled', true], ['email_verified_at', '2026-01-01'], ['kind', 'staff'], ['profile', ['role' => 'Super Admin']]];
    }

    public function test_invalid_phone_and_weak_password_create_no_partial_identity(): void
    {
        $this->browser('POST', '/api/v1/auth/register', [...$this->registration(), 'phone' => '0912345678'])->assertUnprocessable();
        $this->browser('POST', '/api/v1/auth/register', [...$this->registration(), 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_csrf_is_required_for_login_registration_recovery_and_logout_even_with_fetch_metadata(): void
    {
        foreach (['auth/login', 'auth/register', 'auth/password/forgot', 'auth/password/reset', 'auth/email/verify', 'auth/email/resend', 'auth/mfa/challenge'] as $path) {
            $this->browser('POST', '/api/v1/'.$path, [], ['Sec-Fetch-Site' => 'same-origin'], csrf: false)
                ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN')->assertHeader('X-Request-ID');
        }
        $this->signIn($this->customerUser())->assertOk();
        $this->browser('POST', '/api/v1/auth/logout', [], csrf: false)->assertForbidden();
        $this->browser('GET', '/api/v1/identity/me')->assertOk();
    }

    #[DataProvider('untrustedOrigins')]
    public function test_exact_origin_rejects_cross_scheme_subdomain_port_suffix_and_missing_origin(?string $origin): void
    {
        $this->browser('POST', '/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'irrelevant'], ['Origin' => $origin])->assertForbidden();
    }

    public static function untrustedOrigins(): array
    {
        return [['http://localhost:8443'], ['https://localhost:8444'], ['https://localhost:8443.attacker.test'],
            ['https://sub.localhost:8443'], ['null'], [null]];
    }

    public function test_login_rotates_actual_session_and_logout_invalidates_replayed_cookie(): void
    {
        $user = $this->customerUser();
        $anonymous = $this->browserCookies;
        $before = $this->sessionId();
        $response = $this->signIn($user)->assertOk();
        self::assertNotSame($before, $this->sessionId());
        foreach ($response->headers->getCookies() as $cookie) {
            self::assertTrue($cookie->isSecure());
            self::assertSame('lax', $cookie->getSameSite());
            self::assertNull($cookie->getDomain());
            self::assertSame($cookie->getName() === '__Host-holoul_session', $cookie->isHttpOnly());
        }
        $authenticated = $this->browserCookies;
        $this->browser('GET', '/api/v1/identity/me')->assertJsonPath('data.id', $user->id);
        $this->browserCookies = $anonymous;
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        $this->browserCookies = $authenticated;
        $this->browser('POST', '/api/v1/auth/logout')->assertOk();
        $this->browserCookies = $authenticated;
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.login_succeeded']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'identity.logged_out']);
    }

    public function test_login_missing_disabled_and_wrong_password_are_indistinguishable(): void
    {
        $user = $this->customerUser();
        $missing = $this->browser('POST', '/api/v1/auth/login', ['email' => Str::uuid7().'@example.test', 'password' => 'bad'])->assertUnauthorized()->json('error');
        $wrong = $this->browser('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'bad'])->assertUnauthorized()->json('error');
        $user->enabled = false;
        $user->save();
        $disabled = $this->signIn($user)->assertUnauthorized()->json('error');
        self::assertSame($missing, $wrong);
        self::assertSame($wrong, $disabled);
        self::assertSame(3, DB::table('audit_events')->where('event_type', 'identity.login_failed')->count());
    }

    public function test_browser_bearer_header_cannot_authenticate(): void
    {
        $this->browser('GET', '/api/v1/identity/me', [], ['Authorization' => 'Bearer 1|invented-token'])->assertUnauthorized();
        self::assertFalse(Schema::hasTable('personal_access_tokens'));
    }

    public function test_staff_password_is_only_a_pending_factor_and_cannot_read_customers_or_me(): void
    {
        $user = $this->customerUser();
        $user->kind = 'staff';
        $user->save();
        $this->signIn($user)->assertAccepted()->assertJsonPath('data.next_step', 'mfa_enrollment');
        $pending = $this->browserCookies;
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        $this->browserCookies = $pending;
        $this->browser('GET', '/api/v1/customers')->assertUnauthorized();
        $this->assertDatabaseCount('identity_sessions', 0);
    }

    public function test_sessions_list_current_awareness_and_revoke_other_browser(): void
    {
        $user = $this->customerUser();
        $this->signIn($user)->assertOk();
        $first = $this->browserCookies;
        $this->initializeBrowser();
        $this->signIn($user)->assertOk();
        $list = $this->browser('GET', '/api/v1/identity/sessions')->assertOk()->json('data');
        self::assertCount(2, $list);
        self::assertCount(1, array_filter($list, fn ($item) => $item['current']));
        self::assertStringNotContainsString('session_hash', json_encode($list));
        $this->browser('POST', '/api/v1/identity/sessions/revoke-others')->assertOk();
        $current = $this->browserCookies;
        $this->browserCookies = $first;
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        $this->browserCookies = $current;
        $this->browser('GET', '/api/v1/identity/me')->assertOk();
    }

    public function test_password_change_revokes_others_and_requires_current_password(): void
    {
        $user = $this->customerUser();
        $this->signIn($user)->assertOk();
        $first = $this->browserCookies;
        $this->initializeBrowser();
        $this->signIn($user)->assertOk();
        $input = ['current_password' => 'bad', 'password' => 'Changed-Password-729', 'password_confirmation' => 'Changed-Password-729'];
        $this->browser('POST', '/api/v1/auth/password/change', $input)->assertUnauthorized();
        $input['current_password'] = 'Correct-Horse-72-River';
        $this->browser('POST', '/api/v1/auth/password/change', $input)->assertOk();
        self::assertTrue(Hash::check($input['password'], $user->refresh()->password));
        $this->browser('GET', '/api/v1/identity/me')->assertOk();
        $this->browserCookies = $first;
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
    }

    public function test_customer_idle_absolute_and_stale_version_sessions_fail_closed(): void
    {
        $user = $this->customerUser();
        foreach (['idle', 'absolute', 'version'] as $condition) {
            $this->initializeBrowser();
            $this->signIn($user)->assertOk();
            $record = IdentitySession::query()->where('user_id', $user->id)->latest('authenticated_at')->firstOrFail();
            if ($condition === 'idle') {
                $record->last_activity_at = now()->subHours(2)->subSecond()->toImmutable();
                $record->save();
            } elseif ($condition === 'absolute') {
                $record->authenticated_at = now()->subDays(8)->toImmutable();
                $record->expires_at = now()->subDay()->toImmutable();
                $record->save();
            } else {
                $user->auth_version++;
                $user->save();
            }
            $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
        }
    }

    public function test_customer_isolation_across_direct_nested_list_search_update_and_malformed_identifiers(): void
    {
        $a = $this->customerUser();
        $b = $this->customerUser();
        $own = app(CreateCustomer::class)->handle($a->id, '+12025550123', '+1 202 555 0123');
        $foreign = app(CreateCustomer::class)->handle($b->id, '+442079460018', '+44 20 7946 0018');
        $this->signIn($a)->assertOk();
        $this->browser('GET', '/api/v1/customers')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->browser('GET', '/api/v1/customers?search=442079460018')->assertJsonCount(0, 'data');
        foreach (['customers/'.$foreign->id, 'identities/'.$a->id.'/customers/'.$foreign->id,
            'identities/'.$b->id.'/customers/'.$own->id, 'customers/not-a-uuid', 'customers/00000000-0000-0000-0000-000000000000'] as $path) {
            $this->browser('GET', '/api/v1/'.$path)->assertNotFound();
            $this->browser('PATCH', '/api/v1/'.$path, ['phone' => '+12025550199'])->assertNotFound();
        }
        $this->browser('PATCH', '/api/v1/customers/'.$own->id, ['phone' => '+12025550199', 'user_id' => $b->id])->assertUnprocessable();
        $this->browser('PATCH', '/api/v1/identity/me', ['full_name' => 'New Name', 'kind' => 'staff'])->assertUnprocessable();
        $this->browser('GET', '/api/v1/identity/staff/'.$b->id)->assertForbidden();
        $this->assertDatabaseHas('customers', ['id' => $foreign->id, 'phone_e164' => '+442079460018']);
        $this->browser('PATCH', '/api/v1/customers/'.$own->id, ['phone' => '+12025550199'])->assertOk();
    }

    public function test_login_and_recovery_rate_limits_count_normalized_accounts(): void
    {
        $email = Str::uuid7().'@example.test';
        for ($i = 0; $i < 5; $i++) {
            $this->browser('POST', '/api/v1/auth/login', ['email' => $i % 2 ? strtoupper($email) : $email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->browser('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'wrong'])->assertTooManyRequests()->assertHeader('Retry-After');
        foreach (['forgot', 'resend'] as $purpose) {
            $path = $purpose === 'forgot' ? 'auth/password/forgot' : 'auth/email/resend';
            for ($i = 0; $i < 3; $i++) {
                $this->browser('POST', '/api/v1/'.$path, ['email' => $email])->assertAccepted();
            }
            $this->browser('POST', '/api/v1/'.$path, ['email' => $email])->assertTooManyRequests();
        }
    }

    public function test_known_and_unknown_recovery_requests_return_identical_generic_payloads(): void
    {
        $user = $this->customerUser();
        foreach (['auth/password/forgot', 'auth/email/resend'] as $path) {
            $known = $this->browser('POST', '/api/v1/'.$path, ['email' => $user->email])->assertAccepted()->json();
            $unknown = $this->browser('POST', '/api/v1/'.$path, ['email' => Str::uuid7().'@example.test'])->assertAccepted()->json();
            self::assertSame($known, $unknown);
        }
    }

    public function test_real_redis_outage_blocks_sensitive_actions_before_side_effects(): void
    {
        Config::set('database.redis.cache.host', '127.0.0.1');
        Config::set('database.redis.cache.port', 1);
        app('redis')->purge('cache');
        // The initial CSRF response emits telemetry and resolves Redis early.
        // Rebuild its configuration snapshot so this probe reaches the closed port.
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        $this->browser('POST', '/api/v1/auth/register', $this->registration())->assertStatus(503);
        $this->browser('POST', '/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertStatus(503);
        $this->browser('POST', '/api/v1/auth/password/forgot', ['email' => 'missing@example.test'])->assertStatus(503);
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['code' => '123456'])->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('identity_recovery_tokens', 0);
    }

    public function test_database_rejects_invalid_identity_shapes(): void
    {
        $user = $this->customerUser();
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->update(['email' => 'MixedCase@example.test']);
    }

    public function test_hash_looking_password_is_treated_as_literal_plaintext_at_change_boundary(): void
    {
        $user = $this->customerUser();
        $this->signIn($user)->assertOk();
        $literal = Hash::make('weak');
        $this->browser('POST', '/api/v1/auth/password/change', [
            'current_password' => 'Correct-Horse-72-River',
            'password' => $literal, 'password_confirmation' => $literal,
        ])->assertOk();
        self::assertFalse(Hash::check('weak', $user->refresh()->password));
        self::assertTrue(Hash::check($literal, $user->password));
        $this->browser('POST', '/api/v1/auth/logout')->assertOk();
        // Logout no longer emits anonymous state; initialize the next login explicitly.
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->browser('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => $literal])->assertOk();
    }

    public function test_independent_recovery_tokens_do_not_share_a_global_five_attempt_bucket(): void
    {
        foreach (['auth/email/verify', 'auth/password/reset'] as $path) {
            for ($index = 0; $index < 6; $index++) {
                $this->initializeBrowser();
                $data = ['token' => bin2hex(random_bytes(32))];
                if (str_ends_with($path, 'reset')) {
                    $data += ['password' => 'Strong-New-Password-66', 'password_confirmation' => 'Strong-New-Password-66'];
                }
                $this->browser('POST', '/api/v1/'.$path, $data)->assertUnprocessable();
            }
        }
    }

    public function test_staff_mfa_over_real_cookie_transport_and_twelve_hour_absolute_limit(): void
    {
        $user = $this->customerUser();
        $user->kind = 'staff';
        $user->save();
        $this->signIn($user)->assertAccepted();
        $enrollment = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data');
        $otp = (new Google2FA)->getCurrentOtp($enrollment['secret']);
        $codes = $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => $otp])
            ->assertOk()->json('data.recovery_codes');
        self::assertCount(10, $codes);
        $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.kind', 'staff');
        $this->browser('GET', '/api/v1/customers')->assertNotFound();
        $this->browser('POST', '/api/v1/auth/logout')->assertOk();
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->signIn($user)->assertAccepted()->assertJsonPath('data.next_step', 'mfa_challenge');
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['code' => $otp])->assertUnprocessable();
        $this->browser('POST', '/api/v1/auth/mfa/recovery', ['code' => $codes[0]])->assertOk();
        $record = IdentitySession::query()->where('user_id', $user->id)->sole();
        self::assertSame(43200, $record->expires_at->timestamp - $record->authenticated_at->timestamp);
        $record->authenticated_at = now()->subHours(13)->toImmutable();
        $record->expires_at = now()->subHour()->toImmutable();
        $record->save();
        $this->browser('GET', '/api/v1/identity/me')->assertUnauthorized();
    }

    private function registration(): array
    {
        return ['full_name' => "  Ada \t Lovelace  ", 'email' => ' Ada@Example.Test ', 'password' => 'Correct-Horse-72-River',
            'password_confirmation' => 'Correct-Horse-72-River', 'phone' => '+1 202 555 0123'];
    }
}
