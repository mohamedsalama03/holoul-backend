<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AuthorizationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('changes')]
    public function test_parallel_demotion_or_disabling_preserves_one_enabled_super_admin(string $change): void
    {
        $authority = app(RoleAuthority::class);
        $roleId = $authority->roleId(Role::SuperAdmin);
        $hash = Hash::make('LongTestPassword42!');
        $processes = [];

        for ($i = 0; $i < 2; $i++) {
            $email = Str::uuid7().'@example.test';
            $user = User::query()->create(['full_name' => 'Security Operator', 'email' => $email, 'email_display' => $email, 'password' => $hash, 'kind' => 'staff', 'enabled' => true, 'auth_version' => 1]);
            DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId, 'user_kind' => 'staff']);
            $processes[] = new Process([PHP_BINARY, __DIR__.'/Fixtures/change_staff_worker.php', $user->id, $change], base_path(), timeout: 25);
        }

        DB::beginTransaction();

        try {
            $authority->lockChanges();

            foreach ($processes as $process) {
                $process->start();
            }

            $deadline = microtime(true) + 15;
            $waiting = 0;

            while ($waiting < 2 && microtime(true) < $deadline) {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_locks')->join('pg_stat_activity', 'pg_stat_activity.pid', '=', 'pg_locks.pid')
                    ->where('pg_locks.locktype', 'advisory')->where('pg_locks.granted', false)
                    ->where('pg_stat_activity.application_name', 'holoul-b2-admin-race')->count();

                if ($waiting < 2) {
                    usleep(20_000);
                }
            }

            self::assertSame(2, $waiting, 'Both independently authenticated requests must compete for the security mutation lock. '.implode(' ', array_map(fn (Process $process): string => $process->getErrorOutput().$process->getOutput(), $processes)));
            DB::commit();
            $statuses = [];

            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $statuses[] = $result['status'];
            }

            sort($statuses);
            self::assertSame([200, 409], $statuses);
            self::assertSame(1, DB::table('users')->join('user_roles', 'user_roles.user_id', '=', 'users.id')->where('users.enabled', true)->where('user_roles.role_id', $roleId)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    /** @return array<string,array{string}> */
    public static function changes(): array
    {
        return ['parallel demotions' => ['demote'], 'parallel disables' => ['disable']];
    }
}
