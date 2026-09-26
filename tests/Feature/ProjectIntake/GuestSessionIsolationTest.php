<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use App\Modules\Identity\Mfa\MfaCredential;
use App\Modules\Identity\Models\IdentitySession;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class GuestSessionIsolationTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;
    use IntakeFixtures;

    private string $redisPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redisPrefix = 'guest_session_'.Str::uuid7().'_';
        Config::set('database.redis.options.prefix', $this->redisPrefix);
        app('redis')->purge('cache');
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        $this->initializeBrowser();
    }

    public function test_public_taxonomy_never_creates_or_updates_any_session(): void
    {
        $taxonomy = $this->intakeTaxonomy();
        $this->browserCookies = [];
        $before = DB::table('sessions')->orderBy('id')->get()->toJson();
        foreach (['/api/v1/intake/categories', '/api/v1/intake/categories/'.$taxonomy['category_id'].'/subcategories'] as $path) {
            $this->noCookies($this->browser('GET', $path)->assertOk());
        }
        self::assertSame($before, DB::table('sessions')->orderBy('id')->get()->toJson());
        $this->initializeBrowser();
        $this->signIn($this->intakeCustomer())->assertOk();
        DB::table('sessions')->where('id', $this->sessionId())->update(['last_activity' => time() - 10]);
        $before = DB::table('sessions')->orderBy('id')->get()->toJson();
        $this->noCookies($this->browser('GET', '/api/v1/intake/categories')->assertOk());
        self::assertSame($before, DB::table('sessions')->orderBy('id')->get()->toJson());
        $stale = $this->sessionId();
        DB::table('sessions')->where('id', $stale)->delete();
        $this->noCookies($this->browser('GET', '/api/v1/intake/categories')->assertOk());
        self::assertFalse(DB::table('sessions')->where('id', $stale)->exists());
    }

    public function test_guest_operations_preserve_bootstrap_and_another_authenticated_browser(): void
    {
        $user = $this->intakeCustomer();
        $this->signIn($user)->assertOk();
        $authenticated = $this->browserCookies;
        $this->initializeBrowser();
        $guest = $this->sessionId();
        DB::table('sessions')->where('id', $guest)->update(['last_activity' => time() - 10]);
        $before = DB::table('sessions')->orderBy('id')->get()->toJson();
        $this->guestSubmission($user->email);
        self::assertSame($before, DB::table('sessions')->orderBy('id')->get()->toJson());
        self::assertTrue(hash_equals($guest, $this->sessionId()));
        $this->browserCookies = $authenticated;
        $this->noCookies($this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.id', $user->id));
        $this->noCookies($this->browser('POST', '/api/v1/guest/project-requests')->assertForbidden());
        $this->assertDatabaseCount('request_revisions', 1);
    }

    public function test_login_during_delayed_guest_creation_cannot_resurrect_the_old_session(): void
    {
        $user = $this->intakeCustomer();
        $old = $this->sessionId();
        [$barrier, $peers] = $this->blocked($this->browserCookies, 'after', 'POST', '/api/v1/guest/project-requests');
        try {
            $this->signIn($user)->assertOk();
            $new = $this->sessionId();
            self::assertFalse(hash_equals($old, $new));
            $this->finish($barrier, $peers, 201);
            self::assertFalse(DB::table('sessions')->where('id', $old)->exists(), 'Late guest work must not recreate the pre-login session.');
            self::assertTrue(hash_equals($new, $this->sessionId()));
            $this->browser('GET', '/api/v1/identity/me')->assertOk();
            $this->browser('POST', '/api/v1/auth/password/confirm', ['password' => 'Correct-Horse-72-River'])->assertOk();
        } finally {
            $this->cleanup($barrier, $peers);
        }
    }

    public function test_stale_authenticated_request_and_public_guest_work_cannot_clobber_login(): void
    {
        $user = $this->intakeCustomer();
        $this->signIn($user)->assertOk();
        [$barrier, $peers] = $this->blocked($this->browserCookies, 'before');
        try {
            $this->browser('POST', '/api/v1/identity/sessions/revoke-others')->assertOk();
            $authenticated = $this->browserCookies;
            $new = $this->sessionId();
            $this->initializeBrowser();
            $this->noCookies($this->browser('GET', '/api/v1/intake/categories')->assertOk());
            $this->guestSubmission($user->email);
            $this->browserCookies = $authenticated;
            $this->finish($barrier, $peers, 401);
            self::assertTrue(hash_equals($new, $this->sessionId()));
            $this->browser('GET', '/api/v1/identity/me')->assertOk();
        } finally {
            $this->cleanup($barrier, $peers);
        }
    }

    public static function phases(): array
    {
        return [['before', 401], ['after', 200]];
    }

    #[DataProvider('phases')]
    public function test_claim_during_rotation_is_atomic_and_never_reissues_cookies(string $phase, int $status): void
    {
        $user = $this->intakeCustomer();
        [$id, $token] = $this->guestSubmission($user->email);
        $snapshot = (array) DB::table('request_revisions')->where('request_id', $id)->first();
        $this->signIn($user)->assertOk();
        $old = $this->sessionId();
        $headers = ['Idempotency-Key' => (string) Str::uuid7()];
        [$barrier, $peers] = $this->blocked($this->browserCookies, $phase, 'POST', '/api/v1/project-request-claims', 1, ['token' => $token], $headers);
        try {
            $this->browser('POST', '/api/v1/identity/sessions/revoke-others')->assertOk();
            $new = $this->sessionId();
            self::assertFalse(hash_equals($old, $new));
            $this->finish($barrier, $peers, $status);
            $this->assertDatabaseCount('intake_guest_claims', $phase === 'before' ? 0 : 1);
            $this->noCookies($this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], $headers)->assertOk());
            $this->assertDatabaseCount('intake_guest_claims', 1);
            self::assertSame($snapshot, (array) DB::table('request_revisions')->where('request_id', $id)->first());
            self::assertTrue(hash_equals($new, $this->sessionId()));
            $this->browser('GET', '/api/v1/project-requests/'.$id)->assertOk();
        } finally {
            $this->cleanup($barrier, $peers);
        }
    }

    public function test_login_logout_and_login_expire_guest_draft_binding_without_losing_claim_token(): void
    {
        $user = $this->intakeCustomer();
        [$id, $token] = $this->guestSubmission($user->email);
        $draft = $this->browser('POST', '/api/v1/guest/project-requests')->assertCreated();
        $path = '/api/v1/guest/project-requests/'.$draft->json('data.draft_id').'/submissions';
        $headers = ['X-Intake-Capability' => $draft->json('data.capability'), 'If-Match' => $draft->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()];
        $input = [...$this->intakeInput(), 'full_name' => 'Guest Person', 'email' => $user->email, 'phone' => '+218912345678'];
        $this->signIn($user)->assertOk();
        $this->noCookies($this->browser('POST', $path, $input, $headers)->assertForbidden());
        $this->noCookies($this->browser('POST', '/api/v1/auth/logout')->assertOk());
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->noCookies($this->browser('POST', $path, $input, $headers)->assertNotFound());
        $this->signIn($user)->assertOk();
        $this->noCookies($this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], ['Idempotency-Key' => (string) Str::uuid7()])->assertOk());
        $this->browser('GET', '/api/v1/project-requests/'.$id)->assertOk();
        $this->assertDatabaseCount('request_revisions', 1);
    }

    public function test_rebootstrapping_a_retired_cookie_cannot_revive_its_old_guest_capability(): void
    {
        $user = $this->intakeCustomer();
        $draft = $this->browser('POST', '/api/v1/guest/project-requests')->assertCreated();
        $retired = $this->browserCookies;
        $old = $this->sessionId();
        $this->signIn($user)->assertOk();
        $authenticated = $this->browserCookies;
        $current = $this->sessionId();
        self::assertFalse(DB::table('sessions')->where('id', $old)->exists());
        $this->browserCookies = $retired;
        // Explicit bootstrap may establish fresh anonymous CSRF state for this old cookie,
        // but it must not revive capabilities from the destroyed session generation.
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        self::assertTrue(hash_equals($old, $this->sessionId()));
        $this->noCookies($this->browser('POST', '/api/v1/guest/project-requests/'.$draft->json('data.draft_id').'/submissions',
            [...$this->intakeInput(), 'full_name' => 'Guest Person', 'email' => $user->email, 'phone' => '+218912345678'],
            ['X-Intake-Capability' => $draft->json('data.capability'), 'If-Match' => $draft->headers->get('ETag'),
                'Idempotency-Key' => (string) Str::uuid7()])->assertNotFound());
        $this->assertDatabaseCount('request_revisions', 0);
        $this->browserCookies = $authenticated;
        self::assertTrue(hash_equals($current, $this->sessionId()));
        $this->browser('GET', '/api/v1/identity/me')->assertOk();
        $this->browser('POST', '/api/v1/auth/password/confirm', ['password' => 'Correct-Horse-72-River'])->assertOk();
    }

    public function test_mfa_completion_does_not_turn_staff_into_a_customer_claimant(): void
    {
        $staff = $this->intakeStaff();
        [$id, $token] = $this->guestSubmission($staff->email);
        $credential = MfaCredential::query()->create(['user_id' => $staff->id, 'secret' => (new Google2FA)->generateSecretKey(),
            'confirmed_at' => now(), 'last_accepted_step' => intdiv(time(), 30) - 2]);
        $this->signIn($staff)->assertAccepted();
        $pending = $this->sessionId();
        $headers = ['Idempotency-Key' => (string) Str::uuid7()];
        $this->noCookies($this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], $headers)->assertUnauthorized());
        // The protected probe correctly destroys invalid partial authentication; start a fresh MFA login.
        $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->signIn($staff)->assertAccepted();
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['code' => (new Google2FA)->getCurrentOtp($credential->secret)])->assertOk();
        self::assertFalse(hash_equals($pending, $this->sessionId()));
        $this->noCookies($this->browser('POST', '/api/v1/project-request-claims', ['token' => $token], $headers)->assertForbidden());
        $this->browser('GET', '/api/v1/identity/me')->assertOk();
        $this->assertDatabaseCount('intake_guest_claims', 0);
        $this->assertDatabaseHas('project_requests', ['id' => $id, 'customer_id' => null]);
        self::assertSame(1, IdentitySession::query()->where('user_id', $staff->id)->count());
    }

    private function guestSubmission(string $email): array
    {
        $draft = $this->browser('POST', '/api/v1/guest/project-requests')->assertCreated();
        $this->noCookies($draft);
        $id = $draft->json('data.draft_id');
        $receipt = $this->browser('POST', '/api/v1/guest/project-requests/'.$id.'/submissions',
            [...$this->intakeInput(), 'full_name' => 'Guest Person', 'email' => $email, 'phone' => '+218912345678'],
            ['X-Intake-Capability' => $draft->json('data.capability'), 'If-Match' => $draft->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->noCookies($receipt);

        return [$id, $receipt->json('data.claim_token')];
    }

    private function finish(string $barrier, array $peers, int $status): void
    {
        $this->release($barrier);
        foreach ($peers as $peer) {
            self::assertSame(0, $peer->wait(), 'Independent guest HTTP worker failed.');
            $result = json_decode($peer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($status, $result['status']);
            self::assertSame([], array_keys($result['cookies']));
            self::assertNotSame(DB::scalar('SELECT pg_backend_pid()'), $result['pid']);
            $this->browserCookies = [...$this->browserCookies, ...$result['cookies']];
        }
    }

    private function cleanup(string $barrier, array $peers): void
    {
        $this->release($barrier);
        foreach ($peers as $peer) {
            if ($peer->isRunning()) {
                $peer->stop(0);
            }
        }
    }

    private function noCookies(TestResponse $response): void
    {
        self::assertSame([], array_map(fn ($cookie): string => $cookie->getName(), $response->headers->getCookies()));
    }

    /** @param array<string,string> $cookies
     * @return array{string,list<Process>}
     */
    private function blocked(array $cookies, string $phase, string $method = 'GET', string $path = '/api/v1/identity/me', int $count = 1, array $input = [], array $headers = []): array
    {
        $barrier = 'session-race-'.Str::uuid7();
        DB::select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$barrier]);
        $peers = [];
        try {
            for ($i = 0; $i < $count; $i++) {
                $peer = new Process([PHP_BINARY, base_path('tests/Fixtures/guest-session-worker.php')], base_path(), timeout: 30);
                $peer->setInput(json_encode(['name' => $barrier.'-'.$i, 'barrier' => $barrier, 'phase' => $phase, 'path' => $path,
                    'method' => $method, 'cookies' => $cookies, 'ip' => $this->browserIp, 'redis_prefix' => $this->redisPrefix, 'input' => $input, 'headers' => $headers], JSON_THROW_ON_ERROR));
                $peer->start();
                $peers[] = $peer;
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'like', $barrier.'%')->where('wait_event_type', 'Lock')->count();
                if ($waiting === $count) {
                    return [$barrier, $peers];
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::fail('Independent HTTP workers did not all reach the PostgreSQL lock barrier.');
        } catch (\Throwable $exception) {
            $this->release($barrier);
            foreach ($peers as $peer) {
                $peer->stop(0);
            }
            throw $exception;
        }
    }

    private function release(string $key): void
    {
        DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
    }
}
