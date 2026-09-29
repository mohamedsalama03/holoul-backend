<?php

declare(strict_types=1);

namespace Tests\Feature\PublicServices;

use App\Modules\Identity\Mfa\MfaCredential;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\TestCase;

final class PublicSessionRaceTest extends TestCase
{
    use CommercialDatabase, IdentityHttp;

    private string $redisPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->redisPrefix = 'public_session_'.Str::uuid7().'_';
        Config::set('database.redis.options.prefix', $this->redisPrefix);
        app('redis')->purge('cache');
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        $this->initializeBrowser();
    }

    public function test_delayed_contact_response_cannot_resurrect_a_session_after_login(): void
    {
        $customer = $this->customerUser();
        $customer->email_verified_at = now();
        $customer->save();
        $old = $this->sessionId();
        [$barrier,$peers] = $this->blocked($this->browserCookies, 'after', 'POST', '/api/v1/public/contact-messages', 1, $this->input(), ['Idempotency-Key' => (string) Str::uuid7()]);
        try {
            $this->signIn($customer)->assertOk();
            $current = $this->sessionId();
            $this->finish($barrier, $peers, 201);
            self::assertFalse(DB::table('sessions')->where('id', $old)->exists());
            self::assertSame($current, $this->sessionId());
            $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.id', $customer->id);
        } finally {
            $this->cleanup($barrier, $peers);
        }
    }

    public function test_stale_authenticated_request_and_guest_contact_do_not_clobber_rotation(): void
    {
        $customer = $this->customerUser();
        $customer->email_verified_at = now();
        $customer->save();
        $this->signIn($customer)->assertOk();
        [$barrier,$peers] = $this->blocked($this->browserCookies, 'before');
        try {
            $this->browser('POST', '/api/v1/identity/sessions/revoke-others')->assertOk();
            $authenticated = $this->browserCookies;
            $current = $this->sessionId();
            $this->initializeBrowser();
            $before = DB::table('sessions')->orderBy('id')->get()->toJson();
            $this->noCookies($this->browser('POST', '/api/v1/public/contact-messages', $this->input(), ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated());
            self::assertSame($before, DB::table('sessions')->orderBy('id')->get()->toJson());
            $this->browserCookies = $authenticated;
            $this->finish($barrier, $peers, 401);
            self::assertSame($current, $this->sessionId());
            $this->browser('GET', '/api/v1/identity/me')->assertOk();
        } finally {
            $this->cleanup($barrier, $peers);
        }
    }

    public function test_contact_during_mfa_rotation_keeps_the_completed_staff_session(): void
    {
        $staff = $this->customerUser();
        $staff->kind = 'staff';
        $staff->email_verified_at = now();
        $staff->save();
        $credential = MfaCredential::query()->create(['user_id' => $staff->id, 'secret' => (new Google2FA)->generateSecretKey(), 'confirmed_at' => now(), 'last_accepted_step' => intdiv(time(), 30) - 2]);
        $this->signIn($staff)->assertAccepted();
        $pending = $this->sessionId();
        [$barrier,$peers] = $this->blocked($this->browserCookies, 'after', 'POST', '/api/v1/public/contact-messages', 1, $this->input(), ['Idempotency-Key' => (string) Str::uuid7()]);
        try {
            $this->browser('POST', '/api/v1/auth/mfa/challenge', ['code' => (new Google2FA)->getCurrentOtp($credential->secret)])->assertOk();
            $current = $this->sessionId();
            self::assertNotSame($pending, $current);
            $this->finish($barrier, $peers, 201);
            self::assertFalse(DB::table('sessions')->where('id', $pending)->exists());
            self::assertSame($current, $this->sessionId());
            $this->browser('GET', '/api/v1/identity/me')->assertOk();
        } finally {
            $this->cleanup($barrier, $peers);
        }
    }

    public function test_contact_before_logout_and_login_cannot_restore_prior_cookies(): void
    {
        $customer = $this->customerUser();
        $customer->email_verified_at = now();
        $customer->save();
        $this->signIn($customer)->assertOk();
        $old = $this->sessionId();
        [$barrier,$peers] = $this->blocked($this->browserCookies, 'after', 'POST', '/api/v1/public/contact-messages', 1, $this->input(), ['Idempotency-Key' => (string) Str::uuid7()]);
        try {
            $this->browser('POST', '/api/v1/auth/logout')->assertOk();
            $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();
            $this->signIn($customer)->assertOk();
            $current = $this->sessionId();
            $this->finish($barrier, $peers, 201);
            self::assertFalse(DB::table('sessions')->where('id', $old)->exists());
            self::assertSame($current, $this->sessionId());
            $this->browser('GET', '/api/v1/identity/me')->assertOk();
        } finally {
            $this->cleanup($barrier, $peers);
        }
    }

    private function input(): array
    {
        return ['full_name' => 'Synthetic Contact', 'email' => Str::uuid7().'@example.test', 'phone' => '+12025550123', 'message' => 'A concurrent synthetic contact message.'];
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
