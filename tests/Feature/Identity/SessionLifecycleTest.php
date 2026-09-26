<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Mfa\MfaCredential;
use App\Modules\Identity\Models\IdentitySession;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Process\Process;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

final class SessionLifecycleTest extends TestCase
{
    use DatabaseMigrations;
    use IdentityHttp;

    private string $redisPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redisPrefix = 'session_race_'.Str::uuid7().'_';
        Config::set('database.redis.options.prefix', $this->redisPrefix);
        app('redis')->purge('cache');
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        $this->initializeBrowser();
    }

    public function test_anonymous_failures_create_no_session_but_intentional_csrf_bootstrap_and_login_work(): void
    {
        $this->browserCookies = [];
        $count = DB::table('sessions')->count();
        $this->noCookies($this->browser('GET', '/api/v1/identity/me')->assertUnauthorized());
        self::assertSame($count, DB::table('sessions')->count());
        $bootstrap = $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
        self::assertCount(2, $bootstrap->headers->getCookies());
        $before = $this->sessionId();
        $this->signIn($this->customerUser())->assertOk();
        self::assertFalse(hash_equals($before, $this->sessionId()), 'Login must rotate the identifier.');
        $this->noCookies($this->browser('GET', '/api/v1/identity/me')->assertOk());
        $this->noCookies($this->browser('POST', '/api/v1/auth/password/confirm', ['password' => 'bad'])->assertUnauthorized());
        $this->browser('GET', '/api/v1/identity/me')->assertOk();
    }

    public static function staleConditions(): array
    {
        return array_map(fn (string $value): array => [$value], ['missing', 'revoked', 'generation', 'disabled', 'idle', 'absolute', 'hash']);
    }

    public function test_anonymous_current_user_probe_preserves_the_existing_csrf_bootstrap(): void
    {
        $anonymous = $this->sessionId();
        $this->noCookies($this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent());
        $this->noCookies($this->browser('GET', '/api/v1/identity/me')->assertUnauthorized());
        self::assertTrue(DB::table('sessions')->where('id', $anonymous)->exists(), 'A valid anonymous CSRF session is not revoked authentication.');
        $this->signIn($this->customerUser())->assertOk();
        self::assertFalse(hash_equals($anonymous, $this->sessionId()), 'Authentication must still rotate the anonymous ID.');
        $this->browser('GET', '/api/v1/identity/me')->assertOk();
    }

    #[DataProvider('staleConditions')]
    public function test_stale_failures_never_rotate_emit_cookies_or_persist_an_anonymous_replacement(string $condition): void
    {
        $user = $this->customerUser();
        $this->signIn($user)->assertOk();
        $old = $this->sessionId();
        $record = IdentitySession::query()->sole();
        match ($condition) {
            'missing' => DB::table('sessions')->where('id', $old)->delete(),
            'revoked' => $record->delete(),
            'generation' => $user->increment('auth_version'),
            'disabled' => $user->update(['enabled' => false]),
            'idle' => $record->update(['last_activity_at' => now()->subDays(2)]),
            'absolute' => $record->update(['authenticated_at' => now()->subDays(8), 'expires_at' => now()->subDay()]),
            'hash' => $record->update(['session_hash' => str_repeat('0', 64)]),
        };
        $count = DB::table('sessions')->count();
        $audits = DB::table('audit_events')->count();
        $this->noCookies($this->browser('GET', '/api/v1/identity/me')->assertUnauthorized());
        self::assertTrue(hash_equals($old, $this->sessionId()), 'A stale failure must not replace browser state.');
        self::assertLessThanOrEqual($count, DB::table('sessions')->count());
        self::assertSame($audits, DB::table('audit_events')->count());
        $this->noCookies($this->browser('POST', '/api/v1/identity/sessions/revoke-others')->assertStatus(403));
    }

    public static function rotations(): array
    {
        return [['revoke', 'before'], ['revoke', 'after'], ['logout-login', 'before'], ['logout-login', 'after'],
            ['password', 'before'], ['password', 'after'], ['mfa', 'before'], ['mfa-csrf', 'after']];
    }

    #[DataProvider('rotations')]
    public function test_real_concurrent_late_requests_cannot_replace_rotated_session(string $transition, string $phase): void
    {
        // Repeat each real race with fresh credentials/sessions and three independent readers.
        for ($iteration = 0; $iteration < 3; $iteration++) {
            $this->initializeBrowser();
            $user = $this->customerUser();
            $credential = null;
            if (str_starts_with($transition, 'mfa')) {
                $user->update(['kind' => 'staff']);
                $credential = MfaCredential::query()->create(['user_id' => $user->id, 'secret' => (new Google2FA)->generateSecretKey(),
                    'confirmed_at' => now(), 'last_accepted_step' => intdiv(time(), 30) - 2]);
                $this->signIn($user)->assertAccepted();
            } else {
                $this->signIn($user)->assertOk();
            }
            $oldJar = $this->browserCookies;
            $oldId = $this->sessionId();
            [$barrier, $peers] = $this->blocked($oldJar, $phase, path: $transition === 'mfa-csrf' ? '/sanctum/csrf-cookie' : '/api/v1/identity/me');
            try {
                $password = 'Correct-Horse-72-River';
                if ($transition === 'revoke') {
                    $this->browser('POST', '/api/v1/identity/sessions/revoke-others')->assertOk();
                } elseif ($transition === 'logout-login') {
                    $this->noCookies($this->browser('POST', '/api/v1/auth/logout')->assertOk());
                    $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
                    $this->signIn($user)->assertOk();
                } elseif ($transition === 'password') {
                    $password = 'Changed-Password-729';
                    $this->browser('POST', '/api/v1/auth/password/change', ['current_password' => 'Correct-Horse-72-River',
                        'password' => $password, 'password_confirmation' => $password])->assertOk();
                } else {
                    $code = (new Google2FA)->oathTotp($credential->secret, intdiv(time(), 30));
                    $this->browser('POST', '/api/v1/auth/mfa/challenge', ['code' => $code])->assertOk();
                }
                $newId = $this->sessionId();
                self::assertFalse(hash_equals($oldId, $newId), 'The security transition must rotate.');
                $this->release($barrier);
                $pids = [];
                foreach ($peers as $peer) {
                    self::assertSame(0, $peer->wait(), 'Independent HTTP worker failed.');
                    $result = json_decode($peer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                    self::assertSame($transition === 'mfa-csrf' ? 204 : ($phase === 'before' ? 401 : 200), $result['status']);
                    self::assertNotContains('__Host-holoul_session', array_keys($result['cookies']));
                    self::assertNotContains('XSRF-TOKEN', array_keys($result['cookies']));
                    $pids[] = $result['pid'];
                    // Apply responses after the rotation, exactly as a browser would.
                    $this->browserCookies = [...$this->browserCookies, ...$result['cookies']];
                }
                self::assertCount(3, array_unique($pids));
                self::assertNotContains(DB::scalar('SELECT pg_backend_pid()'), $pids);
                self::assertTrue(hash_equals($newId, $this->sessionId()), 'Late responses must preserve the new identifier.');
                $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.id', $user->id);
                $this->browser('POST', '/api/v1/auth/password/confirm', ['password' => $password])->assertOk();
                self::assertSame(1, IdentitySession::query()->where('user_id', $user->id)->count());
                self::assertFalse(DB::table('sessions')->where('id', $oldId)->exists());
                $newJar = $this->browserCookies;
                $this->browserCookies = $oldJar;
                $this->noCookies($this->browser('GET', '/api/v1/identity/me')->assertUnauthorized());
                $this->browserCookies = $newJar;
                $this->browser('GET', '/api/v1/identity/me')->assertOk();
            } finally {
                $this->release($barrier);
                foreach ($peers as $peer) {
                    if ($peer->isRunning()) {
                        $peer->stop(0);
                    }
                }
            }
        }
    }

    public function test_delayed_logout_response_cannot_overwrite_a_later_login(): void
    {
        $user = $this->customerUser();
        $this->signIn($user)->assertOk();
        $old = $this->sessionId();
        [$barrier, $peers] = $this->blocked($this->browserCookies, 'after', 'POST', '/api/v1/auth/logout', 1);
        try {
            $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
            $this->signIn($user)->assertOk();
            $new = $this->sessionId();
            self::assertFalse(hash_equals($old, $new), 'The subsequent login must rotate.');
            $this->release($barrier);
            self::assertSame(0, $peers[0]->wait());
            $result = json_decode($peers[0]->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(200, $result['status']);
            self::assertSame([], array_keys($result['cookies']));
            $this->browserCookies = [...$this->browserCookies, ...$result['cookies']];
            $this->browser('GET', '/api/v1/identity/me')->assertOk();
            $this->browser('POST', '/api/v1/auth/password/confirm', ['password' => 'Correct-Horse-72-River'])->assertOk();
            self::assertSame(1, DB::table('audit_events')->where('event_type', 'identity.logged_out')->count());
            self::assertSame(1, IdentitySession::query()->where('user_id', $user->id)->count());
            self::assertFalse(DB::table('sessions')->where('id', $old)->exists());
        } finally {
            $this->release($barrier);
            if ($peers[0]->isRunning()) {
                $peers[0]->stop(0);
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
    private function blocked(array $cookies, string $phase, string $method = 'GET', string $path = '/api/v1/identity/me', int $count = 3): array
    {
        $barrier = 'session-race-'.Str::uuid7();
        DB::select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$barrier]);
        $peers = [];
        try {
            for ($i = 0; $i < $count; $i++) {
                $peer = new Process([PHP_BINARY, base_path('tests/Fixtures/session-lifecycle-worker.php')], base_path(), timeout: 30);
                $peer->setInput(json_encode(['name' => $barrier.'-'.$i, 'barrier' => $barrier, 'phase' => $phase, 'path' => $path,
                    'method' => $method, 'cookies' => $cookies, 'ip' => $this->browserIp, 'redis_prefix' => $this->redisPrefix], JSON_THROW_ON_ERROR));
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
