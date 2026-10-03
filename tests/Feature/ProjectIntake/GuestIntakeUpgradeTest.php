<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use App\Modules\Identity\Contracts\IdentityReader;
use App\Modules\ProjectIntake\Actions\GuestDrafts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CommercialDatabase;
use Tests\Support\DocumentFixtures;
use Tests\Support\IntakeFixtures;
use Tests\Support\PreUsernameIdentityReader;
use Tests\TestCase;

final class GuestIntakeUpgradeTest extends TestCase
{
    use CommercialDatabase;
    use DocumentFixtures;
    use IntakeFixtures;

    public function test_exact_pre_g1_candidate_upgrade_preserves_every_existing_value_and_repeats_without_change(): void
    {
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/g1-baseline-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', base_path($file)), $file);
        }
        $this->artisan('migrate:fresh', ['--path' => array_keys($manifest), '--force' => true])->assertExitCode(0);
        $this->app->instance(IdentityReader::class, new PreUsernameIdentityReader);
        try {
            $this->initializeDocuments();
            $this->quarantined();
            $customer = $this->intakeCustomer();
            $record = $this->createSubmitted($customer);
            self::assertFalse(Schema::hasColumn('project_requests', 'guest_origin'));
            self::assertCount(29, $manifest);
            $tables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"), 'tablename');
            $before = [];
            $columns = [];
            foreach ($tables as $table) {
                $columns[$table] = Schema::getColumnListing($table);
                $before[$table] = $this->snapshot($table, $columns[$table]);
            }
            $this->artisan('migrate', ['--path' => [...array_keys($manifest), 'database/migrations/2026_09_26_000000_extend_intake_for_guests.php'], '--force' => true])->assertExitCode(0);
            $this->assertDatabaseCount('migrations', 30);
            foreach (array_diff($tables, ['migrations']) as $table) {
                self::assertSame($before[$table], $this->snapshot($table, $columns[$table]), $table.' original values');
            }
            $this->assertDatabaseHas('project_requests', ['id' => $record->id, 'guest_origin' => false, 'customer_user_id' => $customer->id]);
            $all = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"), 'tablename');
            $after = [];
            foreach ($all as $table) {
                $after[$table] = $this->snapshot($table, ['*']);
            }
            $this->artisan('migrate', ['--path' => [...array_keys($manifest), 'database/migrations/2026_09_26_000000_extend_intake_for_guests.php'], '--force' => true])->assertExitCode(0);
            foreach ($after as $table => $rows) {
                self::assertSame($rows, $this->snapshot($table, ['*']), $table.' repeat migration');
            }
            $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
            self::assertFalse(Schema::hasColumn('project_requests', 'guest_origin'));
            foreach (array_diff($tables, ['migrations']) as $table) {
                self::assertSame($before[$table], $this->snapshot($table, $columns[$table]), $table.' unused extension downgrade');
            }
            $this->artisan('migrate', ['--path' => [...array_keys($manifest), 'database/migrations/2026_09_26_000000_extend_intake_for_guests.php'], '--force' => true])->assertExitCode(0);
        } finally {
            $this->app->forgetInstance(IdentityReader::class);
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }
    }

    public function test_downgrade_refuses_any_guest_history_without_losing_its_tables_or_values(): void
    {
        $draft = app(GuestDrafts::class)->create('upgrade-test-browser', (string) Str::uuid7());
        $migration = require database_path('migrations/2026_09_26_000000_extend_intake_for_guests.php');
        try {
            DB::transaction(fn () => $migration->down());
            self::fail('A downgrade must not erase guest history.');
        } catch (\PDOException $failure) {
            self::assertSame('55000', $failure->getCode());
        }
        self::assertTrue(Schema::hasColumn('project_requests', 'guest_origin'));
        $this->assertDatabaseHas('project_requests', ['id' => $draft['draft_id'], 'guest_origin' => true]);
        $this->assertDatabaseCount('intake_guest_access', 1);
    }

    private function snapshot(string $table, array $columns): array
    {
        $rows = DB::table($table)->select($columns)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
        sort($rows);

        return $rows;
    }
}
