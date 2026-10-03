<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommercialDatabase;
use Tests\TestCase;

final class PortfolioEditorUpgradeTest extends TestCase
{
    use CommercialDatabase;

    public function test_exact_candidate_upgrade_preserves_all_existing_columns_and_can_repeat(): void
    {
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/portfolio-editor-baseline-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(35, $manifest);
        foreach ($manifest as $path => $hash) {
            self::assertSame($hash, hash_file('sha256', base_path($path)));
        }
        $this->artisan('migrate:fresh', ['--path' => array_keys($manifest), '--force' => true])->assertExitCode(0);
        try {
            $legacy = User::query()->create(['full_name' => 'Existing Staff', 'email' => 'existing@example.test', 'email_display' => 'existing@example.test',
                'kind' => 'staff', 'enabled' => true, 'password' => 'Existing-Password-72', 'auth_version' => 1]);
            $tables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"), 'tablename');
            $before = [];
            $columns = [];
            foreach ($tables as $table) {
                $columns[$table] = Schema::getColumnListing($table);
                $before[$table] = $this->rows($table, $columns[$table]);
            }
            $migration = 'database/migrations/2026_10_03_000000_add_portfolio_editor_role.php';
            $this->artisan('migrate', ['--path' => [$migration], '--force' => true])->assertExitCode(0);
            foreach (array_diff($tables, ['migrations', 'roles', 'role_permissions']) as $table) {
                self::assertSame($before[$table], $this->rows($table, $columns[$table]), $table);
            }
            self::assertNull($legacy->refresh()->username);
            $roleId = DB::table('roles')->where('code', 'portfolio_editor')->sole()->id;
            foreach (['roles', 'role_permissions'] as $table) {
                $key = $table === 'roles' ? 'id' : 'role_id';
                $rows = DB::table($table)->where($key, '<>', $roleId)->select($columns[$table])->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
                sort($rows);
                self::assertSame($before[$table], $rows, $table);
            }
            self::assertSame(7, DB::table('role_permissions')->where('role_id', $roleId)->count());
            $this->assertDatabaseCount('migrations', 36);
            $this->artisan('migrate', ['--path' => [$migration], '--force' => true])->assertExitCode(0);
            $this->assertDatabaseCount('migrations', 36);
            $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
            self::assertFalse(DB::table('roles')->where('code', 'portfolio_editor')->exists());
            $this->artisan('migrate', ['--path' => [$migration], '--force' => true])->assertExitCode(0);
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
