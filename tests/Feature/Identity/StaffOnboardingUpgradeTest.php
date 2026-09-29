<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommercialDatabase;
use Tests\TestCase;

final class StaffOnboardingUpgradeTest extends TestCase
{
    use CommercialDatabase;

    public function test_exact_accepted_g1_upgrade_preserves_values_and_is_repeatable(): void
    {
        // Keep this historical gate on the exact G1 -> Staff transition. Later batches have their own upgrade gates.
        $staffMigration = 'database/migrations/2026_09_27_000000_add_staff_onboarding.php';
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/staff-baseline-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(30, $manifest);
        foreach ($manifest as $path => $hash) {
            self::assertSame($hash, hash_file('sha256', base_path($path)));
        }
        $this->artisan('migrate:fresh', ['--path' => array_keys($manifest), '--force' => true])->assertExitCode(0);
        try {
            User::query()->create(['full_name' => 'Upgrade Staff', 'email' => 'upgrade@example.test', 'email_display' => 'Upgrade@example.test',
                'kind' => 'staff', 'enabled' => true, 'password' => 'Upgrade-Test-Pass42', 'auth_version' => 1]);
            $tables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"), 'tablename');
            $before = [];
            $columns = [];
            foreach ($tables as $table) {
                $columns[$table] = Schema::getColumnListing($table);
                $before[$table] = $this->rows($table, $columns[$table]);
            }
            $this->artisan('migrate', ['--path' => [$staffMigration], '--force' => true])->assertExitCode(0);
            $this->assertDatabaseCount('migrations', 31);
            foreach (array_diff($tables, ['migrations']) as $table) {
                self::assertSame($before[$table], $this->rows($table, $columns[$table]), $table);
            }
            self::assertSame(1, User::query()->sole()->authorization_revision);
            $this->artisan('migrate', ['--path' => [$staffMigration], '--force' => true])->assertExitCode(0);
            $this->assertDatabaseCount('migrations', 31);
            $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
            self::assertFalse(Schema::hasColumn('users', 'authorization_revision'));
            self::assertFalse(Schema::hasTable('identity_staff_invitations'));
            foreach (array_diff($tables, ['migrations']) as $table) {
                self::assertSame($before[$table], $this->rows($table, $columns[$table]), $table);
            }
            $this->artisan('migrate', ['--path' => [$staffMigration], '--force' => true])->assertExitCode(0);
        } finally {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }
    }

    private function rows(string $table, array $columns): array
    {
        $rows = DB::table($table)->select($columns)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
        sort($rows);

        return $rows;
    }
}
