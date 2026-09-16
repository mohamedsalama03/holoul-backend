<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Mfa\MfaCredential;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MfaConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_independent_totp_challenges_accept_the_same_step_only_once(): void
    {
        [$user, $credential] = $this->credential();
        $code = (new Google2FA)->oathTotp($credential->secret, intdiv(time(), 30));
        $this->compete($user, 'challenge', $code);
        self::assertSame(1, DB::table('identity_sessions')->where('user_id', $user->id)->count());
    }

    public function test_independent_recovery_requests_consume_one_code_only_once(): void
    {
        [$user, $credential] = $this->credential();
        $code = bin2hex(random_bytes(16));
        DB::table('identity_mfa_recovery_codes')->insert([
            'id' => (string) Str::uuid7(), 'mfa_id' => $credential->id,
            'code_hash' => hash('sha256', $code), 'consumed_at' => null, 'created_at' => now(),
        ]);
        $this->compete($user, 'recover', $code);
        self::assertSame(1, DB::table('identity_mfa_recovery_codes')->whereNotNull('consumed_at')->count());
        self::assertSame(1, DB::table('identity_sessions')->where('user_id', $user->id)->count());
    }

    private function compete(User $user, string $method, string $code): void
    {
        $application = 'mfa-proof-'.Str::uuid7();
        $program = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            Illuminate\Support\Facades\DB::select('SELECT set_config(?, ?, false)', ['application_name', $argv[5]]);
            $request = Illuminate\Http\Request::create('https://localhost/api/v1/auth/mfa');
            $store = new Illuminate\Session\Store('mfa-peer', new Illuminate\Session\ArraySessionHandler(120));
            $store->start();
            $request->setLaravelSession($store);
            $request->attributes->set('request_id', (string) Illuminate\Support\Str::uuid7());
            $app->instance('request', $request);
            $app->instance('session.store', $store);
            Illuminate\Support\Facades\Auth::forgetGuards();
            $store->put([
                'identity.pending_user_id' => $argv[1], 'identity.pending_auth_version' => (int) $argv[2],
                'identity.pending_started_at' => time(), 'identity.password_confirmed_at' => time(),
            ]);
            try {
                $app->make(App\Modules\Identity\Mfa\MfaActions::class)->{$argv[4]}($request, $argv[3]);
                echo 'accepted';
            } catch (Illuminate\Validation\ValidationException) {
                echo 'rejected';
            }
            PHP;
        $peers = [];
        DB::beginTransaction();

        try {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            for ($index = 0; $index < 2; $index++) {
                $peer = new Process([PHP_BINARY, '-r', $program, '--', $user->id, (string) $user->auth_version, $code, $method, $application.'-'.$index], base_path(), timeout: 15);
                $peer->start();
                $peers[] = $peer;
            }
            $deadline = microtime(true) + 8;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'like', $application.'%')->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);

            self::assertSame(2, $waiting, 'Both independent PostgreSQL connections must reach the held user lock. '.implode(' ', array_map(fn (Process $peer): string => $peer->getErrorOutput().$peer->getOutput(), $peers)));
            DB::commit();
            $results = [];
            foreach ($peers as $peer) {
                self::assertSame(0, $peer->wait(), $peer->getErrorOutput());
                $results[] = trim($peer->getOutput());
            }
            sort($results);
            self::assertSame(['accepted', 'rejected'], $results);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($peers as $peer) {
                if ($peer->isRunning()) {
                    $peer->stop(0);
                }
            }
        }
    }

    /** @return array{User, MfaCredential} */
    private function credential(): array
    {
        $user = User::query()->create([
            'full_name' => 'Concurrent MFA Staff', 'email' => 'mfa-peer@example.test',
            'email_display' => 'mfa-peer@example.test', 'password' => 'ExamplePassword123!',
            'kind' => 'staff', 'enabled' => true, 'auth_version' => 1,
        ]);
        $credential = MfaCredential::query()->create([
            'user_id' => $user->id, 'secret' => (new Google2FA)->generateSecretKey(),
            'confirmed_at' => now(), 'last_accepted_step' => intdiv(time(), 30) - 2,
        ]);

        return [$user, $credential];
    }
}
