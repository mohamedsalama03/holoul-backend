<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MigrationUpgradeTest extends TestCase
{
    /**
     * SHA-256 of raw git-show bytes from approved B2 commit
     * 8d7baa66da9f56f2b6cb26b09e0ad4268203fa14. The verification image
     * needs neither Git nor repository metadata to verify this baseline.
     *
     * @var array<string, string>
     */
    private const array B2_MIGRATIONS = [
        'database/migrations/2026_09_16_000000_create_framework_infrastructure.php' => '57c6a2b9a7fb4f1f03db156596835496689fa3083b493b76b6f888e94e9bf105',
        'database/migrations/2026_09_16_000100_create_audit_events_table.php' => 'f8a2670b19a6ebc8d3f188ef44a17768f4411f41d5e1f1551812eaf292ce9303',
        'database/migrations/2026_09_16_000200_create_async_operations_table.php' => '60a438c9fc47af553e9612c2212a06eca254b1ad285e02d0f40f6d238f6a526f',
        'database/migrations/2026_09_16_010000_create_identity_core.php' => 'a113f8fe8c4a57435cd1df5494fe91a07bc7f61927c141031c162c3f10a90e3e',
        'database/migrations/2026_09_16_010100_create_customers_table.php' => '7b66b2e6206c8539dd5a2a83c1ca70aaefc2d200481e6c662b55812d6ca88775',
        'database/migrations/2026_09_16_010200_create_identity_authorization_tables.php' => '7d015a05af1d418f7463c137dec469c22db081a7f3336b47cc645e82dccc380c',
        'database/migrations/2026_09_16_010300_create_identity_recovery.php' => '0dc4828f80e56041e66262ed507c5f4b57d72868fc5cddd4a77b9326e6dea703',
        'database/migrations/2026_09_16_010400_create_identity_mfa.php' => '4ca66ab6b4f64fc37b74cbac702c8f738f79f051ca9f3549844e501037d250e0',
    ];

    /** @var list<string> */
    private const array B3_PREFIXES = ['020000', '020100', '020200', '020300'];

    /** @var list<string> */
    private const array B2_RECORD_TABLES = [
        'users', 'customers', 'sessions', 'identity_sessions', 'roles', 'user_roles',
        'identity_recovery_tokens', 'identity_recovery_mail', 'identity_mfa',
        'identity_mfa_recovery_codes', 'audit_events', 'async_operations',
    ];

    /** @var list<string> */
    private const array B3_TABLES = [
        'currencies', 'categories', 'subcategories', 'project_requests', 'request_drafts',
        'request_revisions', 'request_assignments', 'information_requests', 'information_responses',
        'information_resolutions', 'request_state_changes', 'intake_submission_keys',
        'intake_notification_intents',
    ];

    public function test_exact_approved_b2_schema_upgrades_without_losing_identity_data_or_security_guards(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('holoul_test', DB::connection()->getDatabaseName());
        foreach (self::B2_MIGRATIONS as $path => $sha256) {
            $this->assertFileExists(base_path($path));
            $this->assertSame($sha256, hash_file('sha256', base_path($path)), 'An approved B2 migration changed: '.$path);
        }
        $upgradePaths = $this->b3MigrationPaths();

        // DatabaseMigrations would run B3 before the exact B2 fixture exists.
        $this->artisan('migrate:fresh', ['--path' => array_keys(self::B2_MIGRATIONS), '--force' => true])->assertExitCode(0);

        try {
            $this->assertDatabaseCount('migrations', 8);
            foreach (self::B3_TABLES as $table) {
                $this->assertFalse(Schema::hasTable($table), 'B3 unexpectedly exists in the approved B2 baseline: '.$table);
            }
            $fixture = $this->insertB2Fixture();
            $recordsBefore = $this->snapshotTables(self::B2_RECORD_TABLES);
            $migrationsBefore = DB::table('migrations')->orderBy('id')->get()->all();
            $permissionsBefore = DB::table('permissions')->orderBy('id')->get()->all();
            $permissionIds = DB::table('permissions')->orderBy('id')->pluck('id')->all();
            $grantsBefore = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->all();
            $this->assertCount(7, $permissionsBefore);
            $this->assertDatabaseCount('roles', 8);
            $this->assertRuntimePrivileges();

            $this->artisan('migrate', ['--path' => $upgradePaths, '--force' => true])->assertExitCode(0);

            $this->assertDatabaseCount('migrations', 12);
            $this->assertSame(4, DB::table('migrations')->where('batch', 2)->count());
            $this->assertEquals($migrationsBefore, DB::table('migrations')->where('batch', 1)->orderBy('id')->get()->all());
            foreach (self::B3_TABLES as $table) {
                $this->assertTrue(Schema::hasTable($table), 'A B3 table was not installed: '.$table);
            }
            $this->assertSame($recordsBefore, $this->snapshotTables(self::B2_RECORD_TABLES));
            $this->assertEquals($permissionsBefore, DB::table('permissions')->whereIn('id', $permissionIds)->orderBy('id')->get()->all());
            $this->assertEquals($grantsBefore, DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->orderBy('role_id')->orderBy('permission_id')->get()->all());
            $this->assertGreaterThan(7, DB::table('permissions')->count());
            $this->assertDatabaseHas('currencies', ['code' => 'USD', 'exponent' => 2]);
            $this->assertDatabaseHas('currencies', ['code' => 'LYD', 'exponent' => 3]);
            $this->assertRuntimePrivileges();

            $allTables = [...self::B2_RECORD_TABLES, ...self::B3_TABLES, 'permissions', 'role_permissions', 'migrations'];
            $afterUpgrade = $this->snapshotTables($allTables);
            $this->artisan('migrate', ['--path' => $upgradePaths, '--force' => true])->assertExitCode(0);
            $this->assertSame($afterUpgrade, $this->snapshotTables($allTables), 'Repeating the B3 upgrade must not mutate data or reseed catalogs.');

            $this->assertPreservedConstraints($fixture);
            $this->assertSame($afterUpgrade, $this->snapshotTables($allTables));
        } finally {
            $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        }
    }

    /** @return list<string> */
    private function b3MigrationPaths(): array
    {
        $paths = [];
        foreach (self::B3_PREFIXES as $prefix) {
            $matches = glob(base_path('database/migrations/2026_09_16_'.$prefix.'_*.php')) ?: [];
            $this->assertCount(1, $matches, 'Exactly one B3 migration must use prefix '.$prefix);
            $paths[] = 'database/migrations/'.basename($matches[0]);
        }

        return $paths;
    }

    /** @return array<string, string> */
    private function insertB2Fixture(): array
    {
        // SQL fixtures intentionally avoid current domain models/actions: B3
        // application behavior must not alter the historical starting data.
        $ids = [];
        foreach (['customer_user', 'other_customer_user', 'staff', 'customer', 'other_customer', 'identity_session', 'audit', 'operation', 'request', 'token', 'mail', 'mfa'] as $name) {
            $ids[$name] = (string) Str::uuid7();
        }
        $ids['session'] = Str::random(40);
        $now = now()->toImmutable();
        $passwordHash = Hash::make('Approved-B2-Fixture-72');
        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($ids, $now, $passwordHash, $token): void {
            foreach (['customer_user' => 'customer', 'other_customer_user' => 'customer', 'staff' => 'staff'] as $name => $kind) {
                DB::table('users')->insert([
                    'id' => $ids[$name], 'full_name' => 'Approved B2 '.$name,
                    'email' => $name.'@example.test', 'email_display' => $name.'@Example.test',
                    'password' => $passwordHash, 'kind' => $kind, 'enabled' => true,
                    'email_verified_at' => $now, 'auth_version' => 7, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('user_roles')->insert([
                    'user_id' => $ids[$name], 'user_kind' => $kind,
                    'role_id' => DB::table('roles')->where('code', $kind === 'staff' ? 'super_admin' : 'customer')->value('id'),
                ]);
            }
            foreach (['customer' => 'customer_user', 'other_customer' => 'other_customer_user'] as $customer => $user) {
                DB::table('customers')->insert([
                    'id' => $ids[$customer], 'user_id' => $ids[$user], 'customer_kind' => 'customer',
                    'phone_e164' => '+218912345678', 'phone_display' => '+218 91 234 5678',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('sessions')->insert([
                'id' => $ids['session'], 'user_id' => $ids['customer_user'], 'ip_address' => null, 'user_agent' => null,
                'payload' => base64_encode(Crypt::encrypt(serialize([
                    '_token' => Str::random(40), Auth::guard('web')->getName() => $ids['customer_user'],
                    'identity' => [
                        'session_id' => $ids['identity_session'], 'auth_version' => 7,
                        'authenticated_at' => $now->timestamp, 'mfa_verified' => false,
                        'password_confirmed_at' => $now->timestamp,
                    ],
                ]))), 'last_activity' => $now->timestamp,
            ]);
            DB::table('sessions')->insert([
                'id' => Str::random(40), 'user_id' => null, 'ip_address' => null, 'user_agent' => null,
                'payload' => base64_encode(Crypt::encrypt(serialize(['_token' => Str::random(40)]))),
                'last_activity' => $now->timestamp,
            ]);
            DB::table('identity_sessions')->insert([
                'id' => $ids['identity_session'], 'user_id' => $ids['customer_user'],
                'session_hash' => hash_hmac('sha256', $ids['session'], Config::string('app.key')),
                'auth_version' => 7, 'authenticated_at' => $now, 'last_activity_at' => $now,
                'expires_at' => $now->addDays(7),
            ]);
            DB::table('async_operations')->insert([
                'id' => $ids['operation'], 'kind' => 'identity.recovery_mail',
                'logical_key_hash' => hash('sha256', $ids['mail']), 'input_hash' => hash('sha256', $ids['token']),
                'references' => json_encode(['mail_id' => $ids['mail']], JSON_THROW_ON_ERROR),
                'request_id' => $ids['request'], 'state' => 'pending', 'attempts' => 0, 'max_attempts' => 5, 'fence' => 0,
                'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('audit_events')->insert([
                'id' => $ids['audit'], 'actor_id' => $ids['staff'], 'event_type' => 'identity.upgrade_probe',
                'subject_type' => 'user', 'subject_id' => $ids['customer_user'], 'request_id' => $ids['request'],
                'occurred_at' => $now,
                'metadata' => json_encode(['operation_id' => $ids['operation'], 'outcome' => 'succeeded'], JSON_THROW_ON_ERROR),
            ]);
            DB::table('identity_recovery_tokens')->insert([
                'id' => $ids['token'], 'user_id' => $ids['customer_user'], 'purpose' => 'password_reset',
                'token_hash' => hash('sha256', $token), 'email_hash' => hash('sha256', 'customer_user@example.test'),
                'auth_version' => 7, 'expires_at' => $now->addHour(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('identity_recovery_mail')->insert([
                'id' => $ids['mail'], 'user_id' => $ids['customer_user'], 'recovery_token_id' => $ids['token'],
                'operation_id' => $ids['operation'], 'state' => 'pending',
                'encrypted_payload' => Crypt::encryptString(json_encode([
                    'recipient' => 'customer_user@example.test', 'token' => $token, 'purpose' => 'password_reset',
                ], JSON_THROW_ON_ERROR)), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('identity_mfa')->insert([
                'id' => $ids['mfa'], 'user_id' => $ids['staff'], 'staff_kind' => 'staff',
                'secret' => Crypt::encryptString(str_repeat('A', 32)), 'confirmed_at' => $now,
                'last_accepted_step' => intdiv($now->timestamp, 30), 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ([null, $now] as $consumedAt) {
                DB::table('identity_mfa_recovery_codes')->insert([
                    'id' => (string) Str::uuid7(), 'mfa_id' => $ids['mfa'],
                    'code_hash' => hash('sha256', random_bytes(16)), 'consumed_at' => $consumedAt, 'created_at' => $now,
                ]);
            }
        });

        return $ids;
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, list<string>>
     */
    private function snapshotTables(array $tables): array
    {
        $snapshots = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn (object $row): string => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
            sort($rows);
            $snapshots[$table] = $rows;
        }

        return $snapshots;
    }

    private function assertRuntimePrivileges(): void
    {
        $this->assertSame('holoul_migrator', DB::scalar('SELECT current_user'));
        $this->assertFalse(DB::scalar("SELECT has_schema_privilege('holoul_app', 'public', 'CREATE')"));
        $this->assertFalse(DB::scalar("SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolbypassrls FROM pg_roles WHERE rolname = 'holoul_app'"));
        $this->assertTrue(DB::scalar("SELECT has_table_privilege('holoul_app', 'audit_events', 'SELECT') AND has_table_privilege('holoul_app', 'audit_events', 'INSERT')"));
        $this->assertFalse(DB::scalar("SELECT has_table_privilege('holoul_app', 'audit_events', 'UPDATE') OR has_table_privilege('holoul_app', 'audit_events', 'DELETE') OR has_table_privilege('holoul_app', 'audit_events', 'TRUNCATE')"));
        foreach (['roles', 'permissions', 'role_permissions'] as $table) {
            $this->assertTrue(DB::scalar("SELECT has_table_privilege('holoul_app', ?, 'SELECT')", [$table]));
            $this->assertFalse(DB::scalar("SELECT has_table_privilege('holoul_app', ?, 'INSERT,UPDATE,DELETE,TRUNCATE')", [$table]));
        }
        foreach (['users', 'customers', 'sessions', 'identity_sessions', 'async_operations'] as $table) {
            $this->assertTrue(DB::scalar("SELECT has_table_privilege('holoul_app', ?, 'SELECT') AND has_table_privilege('holoul_app', ?, 'INSERT') AND has_table_privilege('holoul_app', ?, 'UPDATE')", [$table, $table, $table]));
        }
    }

    /** @param array<string, string> $fixture */
    private function assertPreservedConstraints(array $fixture): void
    {
        $this->assertSqlState('23514', fn () => DB::table('customers')->where('id', $fixture['customer'])->update(['user_id' => $fixture['other_customer_user']]));
        $this->assertSqlState('23503', fn () => DB::table('customers')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $fixture['staff'], 'customer_kind' => 'customer',
            'phone_e164' => '+218912345678', 'phone_display' => '+218 91 234 5678',
        ]));
        $this->assertSqlState('23503', fn () => DB::table('sessions')->where('id', $fixture['session'])->update(['user_id' => (string) Str::uuid7()]));
        $customerRoleId = DB::table('roles')->where('code', 'customer')->value('id');
        $this->assertSqlState('23503', fn () => DB::table('user_roles')->insert([
            'user_id' => $fixture['staff'], 'role_id' => $customerRoleId, 'user_kind' => 'staff',
        ]));
        $this->assertSqlState('23514', fn () => DB::table('users')->where('id', $fixture['customer_user'])->update(['email' => 'Uppercase@example.test']));
        $this->assertSqlState('23505', fn () => DB::table('users')->where('id', $fixture['other_customer_user'])->update(['email' => 'customer_user@example.test']));
        $this->assertSqlState('55000', fn () => DB::table('audit_events')->where('id', $fixture['audit'])->update(['event_type' => 'identity.changed']));
        $this->assertSqlState('55000', fn () => DB::table('audit_events')->where('id', $fixture['audit'])->delete());
        $this->assertSqlState('55000', fn () => DB::statement('TRUNCATE TABLE audit_events'));

        DB::statement('SET ROLE holoul_app');
        try {
            $this->assertDatabaseHas('users', ['id' => $fixture['customer_user'], 'auth_version' => 7]);
            $this->assertDatabaseHas('customers', ['id' => $fixture['customer'], 'user_id' => $fixture['customer_user']]);
            $this->assertDatabaseHas('async_operations', ['id' => $fixture['operation'], 'state' => 'pending']);
            $this->assertSqlState('42501', fn () => DB::statement('CREATE TABLE b3_upgrade_forbidden_runtime_ddl (id integer)'));
            $this->assertSqlState('42501', fn () => DB::table('audit_events')->where('id', $fixture['audit'])->delete());
            $this->assertSqlState('42501', fn () => DB::table('permissions')->where('code', 'identity.self.read')->update(['code' => 'identity.forged']));
            $this->assertSqlState('42501', fn () => DB::table('currencies')->where('code', 'LYD')->update(['exponent' => 2]));
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    /** @param callable(): mixed $statement */
    private function assertSqlState(string $expected, callable $statement): void
    {
        try {
            $statement();
            $this->fail('A preserved database guard allowed an invalid upgrade probe.');
        } catch (QueryException $exception) {
            $this->assertSame($expected, $exception->getCode());
        }
    }
}
