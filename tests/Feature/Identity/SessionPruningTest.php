<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Actions\OwnIdentity;
use App\Modules\Identity\Models\IdentitySession;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\PruneIdentitySessions;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SessionPruningTest extends TestCase
{
    use DatabaseMigrations;

    public function test_pruning_uses_separate_staff_and_customer_idle_limits_without_redis(): void
    {
        // Measure the pruning dependency, not optional slow-query telemetry from
        // fixture writes or migration teardown on a contended verification host.
        Config::set('operations.slow_query_ms', PHP_INT_MAX);
        Redis::shouldReceive('connection')->never();
        $staff = $this->user('staff');
        $customer = $this->user('customer');
        $expired = $this->record($customer, 10, -1);
        $staffIdle = $this->record($staff, 1801);
        $customerIdle = $this->record($customer, 7201);
        $staffActive = $this->record($staff, 1200);
        $customerActive = $this->record($customer, 3600);

        $this->artisan(PruneIdentitySessions::class)->expectsOutput('3')->assertSuccessful();
        foreach ([$expired, $staffIdle, $customerIdle] as $id) {
            $this->assertDatabaseMissing('identity_sessions', ['id' => $id]);
        }
        foreach ([$staffActive, $customerActive] as $id) {
            $this->assertDatabaseHas('identity_sessions', ['id' => $id]);
        }
    }

    public function test_pruning_still_completes_when_slow_query_telemetry_cannot_reach_redis(): void
    {
        $id = $this->record($this->user('staff'), 2000);
        Config::set('operations.slow_query_ms', 0);
        Redis::shouldReceive('connection')->with('cache')->atLeast()->once()
            ->andThrow(new \RuntimeException('Redis unavailable'));

        $this->artisan(PruneIdentitySessions::class)->expectsOutput('1')->assertSuccessful();
        $this->assertDatabaseMissing('identity_sessions', ['id' => $id]);
    }

    public function test_each_pass_deletes_at_most_500_rows_and_preserves_active_sessions(): void
    {
        $user = $this->user('customer');
        for ($index = 0; $index < 502; $index++) {
            $this->record($user, 8000);
        }
        $active = $this->record($user, 5);

        $this->artisan(PruneIdentitySessions::class)->expectsOutput('500')->assertSuccessful();
        $this->assertDatabaseCount('identity_sessions', 3);
        $this->assertDatabaseHas('identity_sessions', ['id' => $active]);
        $this->artisan(PruneIdentitySessions::class)->expectsOutput('2')->assertSuccessful();
        $this->artisan(PruneIdentitySessions::class)->expectsOutput('0')->assertSuccessful();
    }

    public function test_pruning_skips_rows_locked_by_an_independent_postgresql_connection(): void
    {
        $id = $this->record($this->user('staff'), 2000);
        Config::set('database.connections.prune_peer', Config::array('database.connections.pgsql'));
        $peer = DB::connection('prune_peer');
        $peer->beginTransaction();
        try {
            $peer->table('identity_sessions')->where('id', $id)->lockForUpdate()->first();
            DB::statement("SET lock_timeout = '200ms'");
            $this->artisan(PruneIdentitySessions::class)->expectsOutput('0')->assertSuccessful();
            $this->assertDatabaseHas('identity_sessions', ['id' => $id]);
            $peer->commit();
            $this->artisan(PruneIdentitySessions::class)->expectsOutput('1')->assertSuccessful();
        } finally {
            if ($peer->transactionLevel() > 0) {
                $peer->rollBack();
            }
            DB::statement("SET lock_timeout = '0'");
            DB::purge('prune_peer');
        }
    }

    public function test_pruned_metadata_rejects_a_cookie_even_if_the_framework_session_row_remains(): void
    {
        $user = $this->user('customer');
        $request = $this->login($user);
        $row = IdentitySession::query()->sole();
        IdentitySession::query()->whereKey($row->id)->update([
            'authenticated_at' => now()->subDay(), 'last_activity_at' => now()->subDay(), 'expires_at' => now()->subSecond(),
        ]);
        DB::table('sessions')->insert([
            'id' => $request->session()->getId(), 'user_id' => $user->id,
            'payload' => 'opaque-test-payload', 'last_activity' => time(),
        ]);
        $this->artisan(PruneIdentitySessions::class)->expectsOutput('1')->assertSuccessful();
        $this->assertDatabaseCount('sessions', 1);

        $this->expectException(AuthenticationException::class);
        app(SessionSecurity::class)->authenticated($request);
    }

    public function test_revoke_others_cannot_revive_a_session_when_security_version_changes_after_authentication(): void
    {
        $user = $this->user('customer');
        $request = $this->login($user);
        IdentitySession::query()->where('user_id', $user->id)->update(['last_activity_at' => now()->subSeconds(30)]);
        $interleaved = false;
        DB::listen(function (QueryExecuted $query) use ($user, &$interleaved): void {
            if (! $interleaved && str_starts_with($query->sql, 'update "identity_sessions"')) {
                $interleaved = true;
                // This committed change occurs after authenticated() loaded its
                // principal but before revokeOthers acquires the user lock.
                DB::table('users')->where('id', $user->id)->increment('auth_version');
            }
        });

        try {
            app(OwnIdentity::class)->revokeOthers($request);
            self::fail('A stale principal unexpectedly established a new session.');
        } catch (AuthenticationException) {
            self::assertTrue($interleaved);
            self::assertSame(2, $user->refresh()->auth_version);
            self::assertSame(1, $request->session()->get('identity.auth_version'));
            self::assertSame(0, IdentitySession::query()->where('auth_version', 2)->count());
        }
    }

    private function user(string $kind): User
    {
        return User::query()->create([
            'full_name' => 'Session Test', 'email' => Str::uuid7().'@example.test',
            'email_display' => 'session@example.test', 'password' => 'ExamplePassword123!',
            'kind' => $kind, 'enabled' => true, 'auth_version' => 1,
        ]);
    }

    private function record(User $user, int $idleSeconds, int $expiresInSeconds = 3600): string
    {
        $id = (string) Str::uuid7();
        DB::table('identity_sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'session_hash' => hash('sha256', $id), 'auth_version' => 1,
            'authenticated_at' => now()->subDays(2), 'last_activity_at' => now()->subSeconds($idleSeconds),
            'expires_at' => now()->addSeconds($expiresInSeconds),
        ]);

        return $id;
    }

    private function login(User $user): Request
    {
        $store = new Store('pruning-test', new ArraySessionHandler(120));
        $store->start();
        $request = Request::create('https://localhost/api/v1/identity');
        $request->setLaravelSession($store);
        $request->attributes->set('request_id', (string) Str::uuid7());
        $store->put('identity.password_confirmed_at', now()->getTimestamp());
        app()->instance('request', $request);
        app()->instance('session.store', $store);
        Auth::forgetGuards();
        $request->setUserResolver(fn () => Auth::guard('web')->user());
        app(SessionSecurity::class)->completeLogin($request, $user, false);

        return $request;
    }
}
