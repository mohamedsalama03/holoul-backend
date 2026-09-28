<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DocumentFixtures;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class G1CandidateUpgradeTest extends TestCase
{
    use DocumentFixtures;
    use IntakeFixtures;

    public function test_exact_populated_b7_to_g1_upgrade_preserves_existing_values_and_repeats(): void
    {
        self::assertSame('holoul_test', DB::connection()->getDatabaseName());
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/b7-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(27, $manifest);
        foreach ($manifest as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', base_path($file)), $file);
        }
        $this->artisan('migrate:fresh', ['--path' => array_keys($manifest), '--force' => true])->assertExitCode(0);
        try {
            $this->initializeDocuments();
            $this->quarantined();
            $customer = $this->intakeCustomer();
            $record = $this->createSubmitted($customer);
            $this->assertDatabaseCount('migrations', 27);
            self::assertFalse(Schema::hasColumn('project_requests', 'guest_origin'));
            $tables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"), 'tablename');
            $before = [];
            $columns = [];
            foreach ($tables as $table) {
                $columns[$table] = Schema::getColumnListing($table);
                $before[$table] = $this->snapshot($table, $columns[$table]);
            }
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            $this->assertDatabaseCount('migrations', 30);
            $this->assertDatabaseCount('permissions', 53);
            foreach ($tables as $table) {
                $after = $this->snapshot($table, $columns[$table]);
                if (in_array($table, ['migrations', 'permissions', 'role_permissions'], true)) {
                    foreach ($before[$table] as $row) {
                        self::assertContains($row, $after, $table.' retains each pre-upgrade row');
                    }
                } else {
                    self::assertSame($before[$table], $after, $table.' original values');
                }
            }
            $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'guest_origin' => false, 'customer_user_id' => $customer->id]);
            self::assertTrue(Schema::hasTable('intake_guest_access'));
            $allTables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"), 'tablename');
            $upgraded = [];
            foreach ($allTables as $table) {
                $upgraded[$table] = $this->snapshot($table, ['*']);
            }
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            foreach ($upgraded as $table => $rows) {
                self::assertSame($rows, $this->snapshot($table, ['*']), $table.' repeated upgrade');
            }
        } finally {
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }
    }

    private function snapshot(string $table, array $columns): array
    {
        $rows = DB::table($table)->select($columns)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
        sort($rows);

        return $rows;
    }
}
