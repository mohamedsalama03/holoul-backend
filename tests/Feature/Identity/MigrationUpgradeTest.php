<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MigrationUpgradeTest extends TestCase
{
    /**
     * SHA256 of the raw `git show <approved-commit>:<path>` bytes, computed
     * from a8881df992758e765c4a9d1f8b360b6f7ef71b79. No Git executable or
     * repository metadata is needed inside the immutable verification image.
     *
     * @var array<string, string>
     */
    private const array B1_MIGRATIONS = [
        'database/migrations/2026_09_16_000000_create_framework_infrastructure.php' => '57c6a2b9a7fb4f1f03db156596835496689fa3083b493b76b6f888e94e9bf105',
        'database/migrations/2026_09_16_000100_create_audit_events_table.php' => 'f8a2670b19a6ebc8d3f188ef44a17768f4411f41d5e1f1551812eaf292ce9303',
        'database/migrations/2026_09_16_000200_create_async_operations_table.php' => '60a438c9fc47af553e9612c2212a06eca254b1ad285e02d0f40f6d238f6a526f',
    ];

    public function test_exact_approved_b1_schema_upgrades_without_losing_infrastructure_records_or_guards(): void
    {
        foreach (self::B1_MIGRATIONS as $path => $sha256) {
            $this->assertFileExists(base_path($path));
            $this->assertSame($sha256, hash_file('sha256', base_path($path)), 'An approved B1 migration changed: '.$path);
        }

        // Deliberately do not use DatabaseMigrations: its automatic full fresh
        // migration would replace the exact B1 starting point being tested.
        $this->artisan('migrate:fresh', ['--path' => array_keys(self::B1_MIGRATIONS), '--force' => true])->assertExitCode(0);

        try {
            $this->assertDatabaseCount('migrations', 3);
            $this->assertFalse(Schema::hasTable('users'));
            $this->assertFalse(Schema::hasTable('customers'));
            $sessionId = Str::random(40);
            $auditId = (string) Str::uuid7();
            $operationId = (string) Str::uuid7();
            $requestId = (string) Str::uuid7();
            $referenceId = (string) Str::uuid7();
            DB::table('sessions')->insert([
                'id' => $sessionId, 'user_id' => null, 'ip_address' => null, 'user_agent' => null,
                'payload' => base64_encode('approved-b1-anonymous-session'), 'last_activity' => time(),
            ]);
            DB::table('audit_events')->insert([
                'id' => $auditId, 'actor_id' => null, 'event_type' => 'foundation.upgrade_probe',
                'subject_type' => 'infrastructure', 'subject_id' => $referenceId, 'request_id' => $requestId,
                'occurred_at' => DB::raw('clock_timestamp()'),
                'metadata' => json_encode(['operation_id' => $operationId, 'outcome' => 'succeeded'], JSON_THROW_ON_ERROR),
            ]);
            DB::table('async_operations')->insert([
                'id' => $operationId, 'kind' => 'infrastructure.upgrade_probe',
                'logical_key_hash' => hash('sha256', 'approved-b1-operation'),
                'input_hash' => hash('sha256', $referenceId),
                'references' => json_encode(['reference_id' => $referenceId], JSON_THROW_ON_ERROR),
                'request_id' => $requestId, 'state' => 'pending', 'attempts' => 0, 'max_attempts' => 5, 'fence' => 0,
                'next_attempt_at' => DB::raw('clock_timestamp()'),
                'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $sessionBefore = DB::table('sessions')->where('id', $sessionId)->sole();
            $auditBefore = DB::table('audit_events')->where('id', $auditId)->sole();
            $operationBefore = DB::table('async_operations')->where('id', $operationId)->sole();
            $this->assertRuntimePrivileges();

            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

            $this->assertDatabaseCount('migrations', 8);
            $this->assertSame(3, DB::table('migrations')->where('batch', 1)->count());
            $this->assertSame(5, DB::table('migrations')->where('batch', 2)->count());
            foreach (['users', 'identity_sessions', 'customers', 'roles', 'permissions', 'role_permissions', 'user_roles', 'identity_recovery_tokens', 'identity_recovery_mail', 'identity_mfa', 'identity_mfa_recovery_codes'] as $table) {
                $this->assertTrue(Schema::hasTable($table), 'A B2 table was not installed: '.$table);
            }
            $this->assertEquals($sessionBefore, DB::table('sessions')->where('id', $sessionId)->sole());
            $this->assertEquals($auditBefore, DB::table('audit_events')->where('id', $auditId)->sole());
            $this->assertEquals($operationBefore, DB::table('async_operations')->where('id', $operationId)->sole());
            $this->assertRuntimePrivileges();
            $this->assertNewIdentityConstraints($sessionId);

            $migrationsBeforeRerun = DB::table('migrations')->orderBy('id')->get()->all();
            $rolesBeforeRerun = DB::table('roles')->orderBy('code')->get()->all();
            $permissionsBeforeRerun = DB::table('permissions')->orderBy('code')->get()->all();
            $grantsBeforeRerun = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->all();
            $this->assertCount(8, $rolesBeforeRerun);
            $this->assertCount(7, $permissionsBeforeRerun);

            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

            $this->assertEquals($migrationsBeforeRerun, DB::table('migrations')->orderBy('id')->get()->all());
            $this->assertEquals($rolesBeforeRerun, DB::table('roles')->orderBy('code')->get()->all());
            $this->assertEquals($permissionsBeforeRerun, DB::table('permissions')->orderBy('code')->get()->all());
            $this->assertEquals($grantsBeforeRerun, DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->all());
            $this->assertEquals($sessionBefore, DB::table('sessions')->where('id', $sessionId)->sole());
            $this->assertEquals($auditBefore, DB::table('audit_events')->where('id', $auditId)->sole());
            $this->assertEquals($operationBefore, DB::table('async_operations')->where('id', $operationId)->sole());

            DB::statement('SET ROLE holoul_app');
            try {
                $this->assertDatabaseCount('audit_events', 1);
                $this->assertSqlState('42501', fn () => DB::statement('CREATE TABLE upgrade_forbidden_runtime_ddl (id integer)'));
                $this->assertSqlState('42501', fn () => DB::table('audit_events')->where('id', $auditId)->delete());
            } finally {
                DB::statement('RESET ROLE');
            }
            // The trigger still protects audit rows even for the migration role.
            $this->assertSqlState('55000', fn () => DB::table('audit_events')->where('id', $auditId)->update(['event_type' => 'foundation.changed']));
        } finally {
            $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        }
    }

    private function assertRuntimePrivileges(): void
    {
        $this->assertSame('holoul_migrator', DB::scalar('SELECT current_user'));
        $this->assertFalse(DB::scalar("SELECT has_schema_privilege('holoul_app', 'public', 'CREATE')"));
        $this->assertTrue(DB::scalar("SELECT has_table_privilege('holoul_app', 'audit_events', 'SELECT') AND has_table_privilege('holoul_app', 'audit_events', 'INSERT')"));
        $this->assertFalse(DB::scalar("SELECT has_table_privilege('holoul_app', 'audit_events', 'UPDATE') OR has_table_privilege('holoul_app', 'audit_events', 'DELETE') OR has_table_privilege('holoul_app', 'audit_events', 'TRUNCATE')"));
        $this->assertTrue(DB::scalar("SELECT has_table_privilege('holoul_app', 'async_operations', 'SELECT') AND has_table_privilege('holoul_app', 'async_operations', 'INSERT') AND has_table_privilege('holoul_app', 'async_operations', 'UPDATE')"));
        $this->assertFalse(DB::scalar("SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolbypassrls FROM pg_roles WHERE rolname = 'holoul_app'"));
    }

    private function assertNewIdentityConstraints(string $sessionId): void
    {
        $this->assertSqlState('23503', fn () => DB::table('sessions')->where('id', $sessionId)->update(['user_id' => (string) Str::uuid7()]));
        $staffId = (string) Str::uuid7();
        $staff = [
            'id' => $staffId, 'full_name' => 'Upgrade Probe', 'email' => 'upgrade@example.test',
            'email_display' => 'upgrade@example.test', 'password' => 'migration-fixture-no-login',
            'kind' => 'staff', 'enabled' => false, 'auth_version' => 1,
        ];
        $this->assertSqlState('23514', fn () => DB::table('users')->insert([...$staff, 'email' => 'Uppercase@example.test']));
        DB::table('users')->insert($staff);
        $this->assertSqlState('23503', fn () => DB::table('customers')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $staffId, 'customer_kind' => 'customer',
            'phone_e164' => '+218912345678', 'phone_display' => '+218 91 234 5678',
        ]));
        $customerRole = DB::table('roles')->where('code', 'customer')->sole();
        $this->assertSqlState('23503', fn () => DB::table('user_roles')->insert([
            'user_id' => $staffId, 'role_id' => $customerRole->id, 'user_kind' => 'staff',
        ]));
        $this->assertFalse(DB::scalar("SELECT has_table_privilege('holoul_app', 'roles', 'INSERT') OR has_table_privilege('holoul_app', 'permissions', 'UPDATE') OR has_table_privilege('holoul_app', 'role_permissions', 'DELETE')"));
        DB::table('users')->where('id', $staffId)->delete();
    }

    /** @param callable(): mixed $statement */
    private function assertSqlState(string $expected, callable $statement): void
    {
        try {
            $statement();
            $this->fail('A database guard allowed an invalid migration probe.');
        } catch (QueryException $exception) {
            $this->assertSame($expected, $exception->getCode());
        }
    }
}
