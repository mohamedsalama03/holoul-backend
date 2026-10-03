<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Modules\Identity\Contracts\IdentityReader;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IntakeFixtures;
use Tests\Support\PreUsernameIdentityReader;
use Tests\TestCase;

final class UnifiedCandidateUpgradeTest extends TestCase
{
    use IntakeFixtures;

    public function test_exact_frozen_g1_upgrade_preserves_populated_history_and_repeats_without_changes(): void
    {
        self::assertSame('holoul_test', DB::connection()->getDatabaseName());
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/unified-g1-baseline-migrations.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('b6d3f27ee9fbde274b04a75c4d4ece35aa8e4de0', $manifest['commit']);
        self::assertCount(30, $manifest['files']);
        foreach ($manifest['files'] as $path => $hash) {
            self::assertSame($hash, hash_file('sha256', base_path($path)), $path);
        }
        Queue::fake();
        $this->artisan('migrate:fresh', ['--path' => array_keys($manifest['files']), '--force' => true])->assertSuccessful();
        $this->app->instance(IdentityReader::class, new PreUsernameIdentityReader);
        try {
            $customer = $this->intakeCustomer();
            $request = $this->createSubmitted($customer);
            $tables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"), 'tablename');
            $columns = [];
            $before = [];
            foreach ($tables as $table) {
                $columns[$table] = Schema::getColumnListing($table);
                $before[$table] = $this->rows($table, $columns[$table]);
            }
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            $this->app->forgetInstance(IdentityReader::class);
            foreach ($before as $table => $rows) {
                $after = $this->rows($table, $columns[$table]);
                if (in_array($table, ['migrations', 'roles', 'permissions', 'role_permissions'], true)) {
                    self::assertSame([], array_values(array_diff($rows, $after)), $table.' lost or changed historical rows');
                } else {
                    self::assertSame($rows, $after, $table);
                }
            }
            $this->assertDatabaseCount('migrations', 36);
            self::assertNull($customer->refresh()->username);
            $this->assertDatabaseHas('project_requests', ['id' => $request->id, 'customer_id' => $request->customer_id]);
            self::assertSame(7, DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')->where('roles.code', 'portfolio_editor')->count());
            $allTables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"), 'tablename');
            $upgraded = [];
            foreach ($allTables as $table) {
                $upgraded[$table] = $this->rows($table, Schema::getColumnListing($table));
            }
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            foreach ($upgraded as $table => $rows) {
                self::assertSame($rows, $this->rows($table, Schema::getColumnListing($table)), $table.' changed on repeat');
            }
            self::assertFalse((bool) DB::scalar("SELECT has_table_privilege('holoul_app','contact_messages','DELETE')"));
            self::assertFalse((bool) DB::scalar("SELECT has_table_privilege('holoul_app','identity_staff_creation_keys','UPDATE')"));
            try {
                DB::transaction(fn () => DB::table('request_revisions')->where('request_id', $request->id)->update(['project_name' => 'Tampered history']));
                self::fail('Historical revision guard disappeared.');
            } catch (QueryException $exception) {
                self::assertSame('55000', $exception->errorInfo[0]);
            }
        } finally {
            $this->app->forgetInstance(IdentityReader::class);
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
    }

    private function rows(string $table, array $columns): array
    {
        $rows = DB::table($table)->select($columns)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
        sort($rows);

        return $rows;
    }
}
