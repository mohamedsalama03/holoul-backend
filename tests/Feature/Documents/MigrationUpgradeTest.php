<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

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
     * SHA-256 of raw git-show bytes from approved B3 commit
     * a22b21a873ca43da9f9c28741d432ba350cf7fa4. The verification image
     * needs neither Git nor repository metadata to verify this baseline.
     *
     * @var array<string, string>
     */
    private const array B3_MIGRATIONS = [
        'database/migrations/2026_09_16_000000_create_framework_infrastructure.php' => '57c6a2b9a7fb4f1f03db156596835496689fa3083b493b76b6f888e94e9bf105',
        'database/migrations/2026_09_16_000100_create_audit_events_table.php' => 'f8a2670b19a6ebc8d3f188ef44a17768f4411f41d5e1f1551812eaf292ce9303',
        'database/migrations/2026_09_16_000200_create_async_operations_table.php' => '60a438c9fc47af553e9612c2212a06eca254b1ad285e02d0f40f6d238f6a526f',
        'database/migrations/2026_09_16_010000_create_identity_core.php' => 'a113f8fe8c4a57435cd1df5494fe91a07bc7f61927c141031c162c3f10a90e3e',
        'database/migrations/2026_09_16_010100_create_customers_table.php' => '7b66b2e6206c8539dd5a2a83c1ca70aaefc2d200481e6c662b55812d6ca88775',
        'database/migrations/2026_09_16_010200_create_identity_authorization_tables.php' => '7d015a05af1d418f7463c137dec469c22db081a7f3336b47cc645e82dccc380c',
        'database/migrations/2026_09_16_010300_create_identity_recovery.php' => '0dc4828f80e56041e66262ed507c5f4b57d72868fc5cddd4a77b9326e6dea703',
        'database/migrations/2026_09_16_010400_create_identity_mfa.php' => '4ca66ab6b4f64fc37b74cbac702c8f738f79f051ca9f3549844e501037d250e0',
        'database/migrations/2026_09_16_020000_create_currencies.php' => '94570bfaff715a6c1d2432a0a2f91c2f56f13111b4e9d98b55b113b45cf49e03',
        'database/migrations/2026_09_16_020100_create_categories.php' => '67c066519d09b271fc3b6dca8dca17afba3a2800c309d52a42389c50571b3f12',
        'database/migrations/2026_09_16_020200_add_intake_permissions.php' => 'be651b514dcfff280f374c168f2dc0615363346d8b4d0e63ac48793491dd054f',
        'database/migrations/2026_09_16_020300_create_project_intake.php' => '5a7765ad104bc72a0d5ac8508adc8dccd7e86d66ba695ebb06023a06db844b20',
    ];

    /** @var list<string> */
    private const array B4_MIGRATIONS = [
        'database/migrations/2026_09_17_030000_create_document_tables.php',
        'database/migrations/2026_09_17_030100_create_intake_document_attachments.php',
        'database/migrations/2026_09_17_030200_seed_document_permissions.php',
    ];

    /** @var list<string> */
    private const array B3_RECORD_TABLES = [
        'users', 'customers', 'sessions', 'identity_sessions', 'roles', 'user_roles',
        'identity_recovery_tokens', 'identity_recovery_mail', 'identity_mfa',
        'identity_mfa_recovery_codes', 'audit_events', 'async_operations',
        'currencies', 'categories', 'subcategories', 'project_requests', 'request_drafts',
        'request_revisions', 'request_assignments', 'information_requests', 'information_responses',
        'information_resolutions', 'request_state_changes', 'intake_submission_keys',
        'intake_notification_intents',
    ];

    /** @var list<string> */
    private const array B4_TABLES = ['documents', 'document_quotas', 'document_orphan_objects', 'document_reconciliation_cursors',
        'intake_draft_documents', 'intake_revision_documents'];

    public function test_exact_approved_b3_schema_upgrades_without_losing_intake_history_identity_or_security_guards(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('holoul_test', DB::connection()->getDatabaseName());
        foreach (self::B3_MIGRATIONS as $path => $sha256) {
            $this->assertFileExists(base_path($path));
            $this->assertSame($sha256, hash_file('sha256', base_path($path)), 'An approved B3 migration changed: '.$path);
        }
        foreach (self::B4_MIGRATIONS as $path) {
            $this->assertFileExists(base_path($path));
        }

        // Historical construction must never invoke B4 application actions or
        // run the new migrations before the exact approved starting point.
        $this->artisan('migrate:fresh', ['--path' => array_keys(self::B3_MIGRATIONS), '--force' => true])->assertExitCode(0);
        try {
            $this->assertDatabaseCount('migrations', 12);
            foreach (self::B4_TABLES as $table) {
                $this->assertFalse(Schema::hasTable($table), 'B4 unexpectedly exists in the approved B3 baseline: '.$table);
            }
            $fixture = $this->insertIntakeFixture($this->insertB3Fixture());
            $baselineColumns = [];
            foreach (self::B3_RECORD_TABLES as $table) {
                $baselineColumns[$table] = Schema::getColumnListing($table);
            }
            $before = $this->snapshotTables(self::B3_RECORD_TABLES, $baselineColumns);
            $sequenceBefore = DB::selectOne('SELECT last_value, is_called FROM request_reference_sequence');
            $this->assertNotNull($sequenceBefore);
            $migrationsBefore = DB::table('migrations')->orderBy('id')->get()->all();
            $permissionsBefore = DB::table('permissions')->orderBy('id')->get()->all();
            $permissionIds = DB::table('permissions')->orderBy('id')->pluck('id')->all();
            $grantsBefore = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->all();
            $this->assertCount(15, $permissionsBefore);
            $this->assertDatabaseCount('roles', 8);
            $this->assertRuntimePrivileges();

            $this->artisan('migrate', ['--path' => self::B4_MIGRATIONS, '--force' => true])->assertExitCode(0);

            $this->assertDatabaseCount('migrations', 15);
            $this->assertSame(3, DB::table('migrations')->where('batch', 2)->count());
            $this->assertEquals($migrationsBefore, DB::table('migrations')->where('batch', 1)->orderBy('id')->get()->all());
            foreach (self::B4_TABLES as $table) {
                $this->assertTrue(Schema::hasTable($table), 'A B4 table was not installed: '.$table);
                $this->assertDatabaseCount($table, $table === 'document_reconciliation_cursors' ? 1 : 0);
            }
            $this->assertDatabaseHas('document_reconciliation_cursors', ['id' => 1, 'cursor' => null]);
            $this->assertSame($before, $this->snapshotTables(self::B3_RECORD_TABLES, $baselineColumns));
            $this->assertSame(2, DB::table('request_revisions')->whereNull('attachment_creation_xid')->count());
            $this->assertEquals($sequenceBefore, DB::selectOne('SELECT last_value, is_called FROM request_reference_sequence'));
            $this->assertEquals($permissionsBefore, DB::table('permissions')->whereIn('id', $permissionIds)->orderBy('id')->get()->all());
            $this->assertEquals($grantsBefore, DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->orderBy('role_id')->orderBy('permission_id')->get()->all());
            $this->assertDatabaseCount('permissions', 17);
            foreach (['documents.read', 'documents.download'] as $permission) {
                $grants = DB::table('roles')->join('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
                    ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                    ->where('permissions.code', $permission)->orderBy('roles.code')->pluck('roles.code')->all();
                $this->assertSame(['business_analyst', 'project_manager', 'reviewer', 'super_admin'], $grants);
            }
            $this->assertRuntimePrivileges();
            foreach (['documents', 'document_quotas', 'document_orphan_objects', 'document_reconciliation_cursors'] as $table) {
                $this->assertTrue(DB::scalar("SELECT has_table_privilege('holoul_app', ?, 'SELECT') AND has_table_privilege('holoul_app', ?, 'INSERT') AND has_table_privilege('holoul_app', ?, 'UPDATE')", [$table, $table, $table]));
                $this->assertFalse(DB::scalar("SELECT has_table_privilege('holoul_app', ?, 'DELETE,TRUNCATE')", [$table]));
            }
            $this->assertFalse(DB::scalar("SELECT has_table_privilege('holoul_app', 'intake_draft_documents', 'UPDATE,TRUNCATE')"));
            $allTables = [...self::B3_RECORD_TABLES, ...self::B4_TABLES, 'permissions', 'role_permissions', 'migrations'];
            $after = $this->snapshotTables($allTables);

            $this->artisan('migrate', ['--path' => self::B4_MIGRATIONS, '--force' => true])->assertExitCode(0);

            $this->assertSame($after, $this->snapshotTables($allTables), 'Repeating the B4 upgrade must not mutate data or reseed catalogs.');
            $this->assertEquals($sequenceBefore, DB::selectOne('SELECT last_value, is_called FROM request_reference_sequence'));
            $this->assertPreservedConstraints($fixture);
            $this->assertIntakeConstraints($fixture);
            $this->assertSame($after, $this->snapshotTables($allTables));
            $this->assertNewAttachmentTransactionGuard($fixture);
            $this->assertSame((int) $sequenceBefore->last_value + 1, DB::scalar("SELECT nextval('request_reference_sequence')"));
        } finally {
            $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        }
    }

    /** @return array<string, string> */
    private function insertB3Fixture(): array
    {
        // SQL fixtures intentionally avoid current domain models/actions: B4
        // application behavior must not alter the historical starting data.
        $ids = [];
        foreach (['customer_user', 'other_customer_user', 'staff', 'customer', 'other_customer', 'identity_session', 'audit', 'operation', 'request', 'token', 'mail', 'mfa'] as $name) {
            $ids[$name] = (string) Str::uuid7();
        }
        $ids['session'] = Str::random(40);
        $now = now()->toImmutable();
        $passwordHash = Hash::make('Approved-B3-Fixture-72');
        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($ids, $now, $passwordHash, $token): void {
            foreach (['customer_user' => 'customer', 'other_customer_user' => 'customer', 'staff' => 'staff'] as $name => $kind) {
                DB::table('users')->insert([
                    'id' => $ids[$name], 'full_name' => 'Approved B3 '.$name,
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

    /** @param array<string, string> $ids
     * @return array<string, string>
     */
    private function insertIntakeFixture(array $ids): array
    {
        foreach (['category', 'subcategory', 'intake_request', 'other_intake_request', 'draft', 'other_draft',
            'revision_one', 'revision_two', 'assignment', 'question', 'response', 'resolution', 'submission_key'] as $name) {
            $ids[$name] = (string) Str::uuid7();
        }
        $now = now()->toImmutable();
        DB::transaction(function () use ($ids, $now): void {
            DB::table('categories')->insert(['id' => $ids['category'], 'name' => 'Archived B3 category', 'slug' => 'approved-b3-category',
                'active' => false, 'display_order' => 5, 'lock_version' => 3, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('subcategories')->insert(['id' => $ids['subcategory'], 'category_id' => $ids['category'],
                'name' => 'Archived B3 subcategory', 'slug' => 'approved-b3-subcategory', 'active' => false,
                'display_order' => 7, 'lock_version' => 4, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('project_requests')->insert([
                ['id' => $ids['intake_request'], 'customer_id' => $ids['customer'], 'customer_user_id' => $ids['customer_user']],
                ['id' => $ids['other_intake_request'], 'customer_id' => $ids['other_customer'], 'customer_user_id' => $ids['other_customer_user']],
            ]);
            foreach (['revision_one' => 1, 'revision_two' => 2] as $key => $number) {
                DB::table('request_revisions')->insert([
                    'id' => $ids[$key], 'request_id' => $ids['intake_request'], 'customer_id' => $ids['customer'],
                    'revision_number' => $number, 'full_name' => 'Original B3 contact '.$number,
                    'email' => 'original'.$number.'@example.test', 'phone_e164' => '+218912345678',
                    'category_id' => $ids['category'], 'subcategory_id' => $ids['subcategory'],
                    'category_label' => 'Original category '.$number, 'subcategory_label' => 'Original subcategory '.$number,
                    'project_name' => 'Approved B3 immutable intake '.$number, 'project_description' => 'Original customer-authored scope '.$number,
                    'budget_unknown' => false, 'budget_minor' => $number === 1 ? 123450 : 1234567,
                    'currency' => $number === 1 ? 'USD' : 'LYD', 'submitted_by' => $ids['customer_user'],
                    'submitted_at' => $now, 'provenance' => $number === 1 ? 'customer_submission' : 'customer_amendment',
                ]);
            }
            $sequence = DB::scalar("SELECT nextval('request_reference_sequence')");
            $this->assertIsInt($sequence);
            $reference = 'REQ-'.$now->utc()->format('Y').'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
            DB::table('project_requests')->where('id', $ids['intake_request'])->update([
                'state' => 'under_review', 'reference' => $reference, 'lock_version' => 9,
                'latest_revision_number' => 2, 'latest_revision_id' => $ids['revision_two'],
                'assigned_staff_id' => $ids['staff'], 'submitted_at' => $now,
            ]);
            DB::table('request_drafts')->insert([
                'id' => $ids['draft'], 'request_id' => $ids['intake_request'], 'customer_id' => $ids['customer'],
                'is_open' => true, 'base_revision_number' => 2, 'category_id' => $ids['category'], 'subcategory_id' => $ids['subcategory'],
                'project_name' => 'Unsubmitted B3 amendment', 'project_description' => 'Editable text distinct from retained snapshots.',
                'budget_unknown' => true, 'budget_minor' => null, 'currency' => null,
            ]);
            DB::table('request_drafts')->insert([
                'id' => $ids['other_draft'], 'request_id' => $ids['other_intake_request'], 'customer_id' => $ids['other_customer'],
                'is_open' => true, 'base_revision_number' => 0, 'project_name' => 'Incomplete customer B draft',
            ]);
            DB::table('request_assignments')->insert(['id' => $ids['assignment'], 'request_id' => $ids['intake_request'],
                'assigned_staff_id' => $ids['staff'], 'assigned_by' => $ids['staff']]);
            DB::table('information_requests')->insert(['id' => $ids['question'], 'request_id' => $ids['intake_request'],
                'customer_id' => $ids['customer'], 'origin_state' => 'under_review', 'question' => 'Original clarification question.',
                'requested_by' => $ids['staff']]);
            DB::table('information_responses')->insert(['id' => $ids['response'], 'information_request_id' => $ids['question'],
                'request_id' => $ids['intake_request'], 'customer_id' => $ids['customer'],
                'response' => 'Original customer clarification.', 'responded_by' => $ids['customer_user']]);
            DB::table('information_resolutions')->insert(['id' => $ids['resolution'], 'information_request_id' => $ids['question'],
                'request_id' => $ids['intake_request'], 'resolution' => 'acknowledged', 'resolved_by' => $ids['staff']]);
            foreach ([['draft', 'submitted'], ['submitted', 'under_review'], ['under_review', 'information_required'], ['information_required', 'under_review']] as [$from, $to]) {
                DB::table('request_state_changes')->insert(['id' => (string) Str::uuid7(), 'request_id' => $ids['intake_request'],
                    'from_state' => $from, 'to_state' => $to, 'actor_id' => $to === 'submitted' ? $ids['customer_user'] : $ids['staff']]);
            }
            DB::table('intake_submission_keys')->insert(['id' => $ids['submission_key'], 'actor_id' => $ids['customer_user'],
                'key_hash' => hash('sha256', 'approved-b3-key'), 'input_hash' => hash('sha256', 'approved-b3-submission'),
                'request_id' => $ids['intake_request'], 'revision_id' => $ids['revision_two'], 'revision_number' => 2,
                'result_version' => 7, 'result_state' => 'under_review', 'reference' => $reference, 'expires_at' => $now->addHours(72)]);
            foreach (['revision_one' => 'intake.submitted', 'revision_two' => 'intake.amended'] as $key => $kind) {
                DB::table('intake_notification_intents')->insert(['id' => (string) Str::uuid7(), 'request_id' => $ids['intake_request'],
                    'revision_id' => $ids[$key], 'kind' => $kind]);
            }
        });

        return $ids;
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, list<string>>  $columns
     * @return array<string, list<string>>
     */
    private function snapshotTables(array $tables, array $columns = []): array
    {
        $snapshots = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get($columns[$table] ?? ['*'])->map(fn (object $row): string => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
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
        $this->assertTrue(DB::scalar("SELECT has_sequence_privilege('holoul_app', 'request_reference_sequence', 'USAGE')"));
        $this->assertTrue(DB::scalar("SELECT has_table_privilege('holoul_app', 'currencies', 'SELECT')"));
        $this->assertFalse(DB::scalar("SELECT has_table_privilege('holoul_app', 'currencies', 'INSERT,UPDATE,DELETE,TRUNCATE')"));
        foreach (['categories', 'subcategories'] as $table) {
            $this->assertTrue(DB::scalar("SELECT has_table_privilege('holoul_app', ?, 'SELECT') AND has_table_privilege('holoul_app', ?, 'INSERT') AND has_table_privilege('holoul_app', ?, 'UPDATE')", [$table, $table, $table]));
            $this->assertFalse(DB::scalar("SELECT has_table_privilege('holoul_app', ?, 'DELETE,TRUNCATE')", [$table]));
        }
        foreach (['request_revisions', 'request_assignments', 'information_requests', 'information_responses', 'information_resolutions', 'request_state_changes', 'intake_notification_intents'] as $table) {
            $this->assertTrue(DB::scalar("SELECT has_table_privilege('holoul_app', ?, 'SELECT') AND has_table_privilege('holoul_app', ?, 'INSERT')", [$table, $table]));
            $this->assertFalse(DB::scalar("SELECT has_table_privilege('holoul_app', ?, 'UPDATE,DELETE,TRUNCATE')", [$table]));
        }
    }

    /** @param array<string, string> $fixture */
    private function assertIntakeConstraints(array $fixture): void
    {
        $this->assertSqlState('23514', fn () => DB::table('project_requests')->where('id', $fixture['intake_request'])->update(['customer_id' => $fixture['other_customer']]));
        $this->assertSqlState('23514', fn () => DB::table('project_requests')->where('id', $fixture['intake_request'])->update(['reference' => 'REQ-2026-99999']));
        $this->assertSqlState('23514', fn () => DB::table('request_drafts')->where('id', $fixture['draft'])->update(['request_id' => $fixture['other_intake_request']]));
        $this->assertSqlState('23503', fn () => DB::table('project_requests')->where('id', $fixture['intake_request'])->update(['latest_revision_number' => 1]));
        $this->assertSqlState('23503', fn () => DB::table('intake_submission_keys')->where('id', $fixture['submission_key'])->update(['actor_id' => $fixture['other_customer_user']]));
        $this->assertSqlState('23514', fn () => DB::table('categories')->where('id', $fixture['category'])->update(['slug' => 'replacement-key', 'lock_version' => 4]));
        $this->assertSqlState('23514', fn () => DB::table('request_drafts')->where('id', $fixture['other_draft'])->update(['budget_minor' => 1]));

        $histories = ['request_revisions', 'request_assignments', 'information_requests', 'information_responses',
            'information_resolutions', 'request_state_changes', 'intake_notification_intents'];
        foreach ($histories as $table) {
            $id = DB::table($table)->value('id');
            $this->assertIsString($id);
            $this->assertSqlState('55000', fn () => DB::table($table)->where('id', $id)->update(['id' => $id]));
            $this->assertSqlState('55000', fn () => DB::table($table)->where('id', $id)->delete());
            $this->assertSqlState('55000', fn () => DB::statement('TRUNCATE TABLE '.$table.' CASCADE'));
        }
        foreach (['categories', 'subcategories', 'currencies'] as $table) {
            $this->assertSqlState('55000', fn () => DB::table($table)->delete());
            $this->assertSqlState('55000', fn () => DB::statement('TRUNCATE TABLE '.$table.' CASCADE'));
        }
        DB::statement('SET ROLE holoul_app');
        try {
            foreach ([...$histories, 'intake_revision_documents'] as $table) {
                $this->assertFalse(DB::scalar("SELECT has_table_privilege(current_user, ?, 'UPDATE,DELETE,TRUNCATE')", [$table]));
                $this->assertSqlState('42501', fn () => DB::table($table)->delete());
                $this->assertSqlState('42501', fn () => DB::statement('TRUNCATE TABLE '.$table.' CASCADE'));
            }
            $this->assertDatabaseHas('request_revisions', ['id' => $fixture['revision_one'], 'currency' => 'USD', 'budget_minor' => 123450]);
            $this->assertDatabaseHas('request_revisions', ['id' => $fixture['revision_two'], 'currency' => 'LYD', 'budget_minor' => 1234567]);
            $this->assertDatabaseHas('request_drafts', ['id' => $fixture['draft'], 'is_open' => true, 'base_revision_number' => 2]);
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    /** @param array<string, string> $fixture */
    private function assertNewAttachmentTransactionGuard(array $fixture): void
    {
        $documentId = (string) Str::uuid7();
        $operationId = (string) Str::uuid7();
        $operation = (array) DB::table('async_operations')->where('id', $fixture['operation'])->sole();
        DB::table('async_operations')->insert([...$operation, 'id' => $operationId, 'kind' => 'documents.scan',
            'logical_key_hash' => hash('sha256', $documentId), 'input_hash' => hash('sha256', $documentId),
            'references' => json_encode(['document_id' => $documentId], JSON_THROW_ON_ERROR)]);
        DB::table('documents')->insert([
            'id' => $documentId, 'customer_id' => $fixture['customer'], 'customer_user_id' => $fixture['customer_user'],
            'parent_id' => $fixture['intake_request'], 'uploader_id' => $fixture['customer_user'],
            'reservation_key_hash' => hash('sha256', 'upgrade-attachment'), 'reservation_input_hash' => hash('sha256', 'upgrade-input'),
            'display_name' => 'upgrade.pdf', 'format' => 'pdf', 'expected_size' => 100, 'expected_sha256' => hash('sha256', 'upgrade-fixture'),
            'storage_key' => 'quarantine/'.$documentId, 'storage_version' => 'upgrade-exact-version',
            'state' => 'quarantined', 'upload_expires_at' => now()->addHour(), 'uploaded_at' => now(),
            'scan_operation_id' => $operationId, 'scan_generation' => 1,
        ]);
        $attachment = ['request_id' => $fixture['intake_request'], 'customer_id' => $fixture['customer'], 'document_id' => $documentId];
        $this->assertSqlState('23514', fn () => DB::table('intake_revision_documents')->insert([
            ...$attachment, 'revision_id' => $fixture['revision_one'],
        ]));
        $revision = (array) DB::table('request_revisions')->where('id', $fixture['revision_two'])->sole();
        $attachedRevision = (string) Str::uuid7();
        $unattachedRevision = (string) Str::uuid7();
        DB::transaction(function () use ($revision, $attachment, $attachedRevision, $unattachedRevision): void {
            $topLevelXid = DB::scalar('SELECT pg_current_xact_id()::text');
            DB::transaction(function () use ($revision, $attachment, $attachedRevision, $unattachedRevision, $topLevelXid): void {
                foreach ([$attachedRevision => 3, $unattachedRevision => 4] as $id => $number) {
                    // The insert trigger must overwrite a forged marker, and
                    // the same top-level marker must survive Laravel savepoints.
                    DB::table('request_revisions')->insert([...$revision, 'id' => $id, 'revision_number' => $number, 'attachment_creation_xid' => '1']);
                    $this->assertSame($topLevelXid, DB::scalar('SELECT attachment_creation_xid::text FROM request_revisions WHERE id=?', [$id]));
                }
                DB::table('intake_revision_documents')->insert([...$attachment, 'revision_id' => $attachedRevision]);
            });
        });
        $this->assertDatabaseHas('intake_revision_documents', [...$attachment, 'revision_id' => $attachedRevision]);
        $this->assertSqlState('23514', fn () => DB::table('intake_revision_documents')->insert([
            ...$attachment, 'revision_id' => $unattachedRevision,
        ]));
        $this->assertSqlState('55000', fn () => DB::table('intake_revision_documents')->where('revision_id', $attachedRevision)->delete());
        $this->assertSqlState('55000', fn () => DB::statement('TRUNCATE TABLE intake_revision_documents CASCADE'));
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
