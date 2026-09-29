<?php

declare(strict_types=1);

namespace Tests\Feature\PublicServices;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class UpgradePublicServicesTest extends TestCase
{
    use IntakeFixtures;

    public function test_exact_upgrade_from_31_migrations_preserves_history_ownership_and_existing_grants(): void
    {
        self::assertSame('holoul_test', DB::connection()->getDatabaseName());
        Queue::fake();
        $baseline = array_values(array_filter(glob(database_path('migrations/*.php')), static fn (string $path): bool => basename($path) < '2026_09_29'));
        self::assertCount(31, $baseline);
        $this->artisan('migrate:fresh', ['--force' => true, '--path' => $baseline, '--realpath' => true])->assertSuccessful();
        $this->assertDatabaseCount('migrations', 31);
        $user = $this->intakeCustomer();
        $request = $this->createSubmitted($user);
        $tables = ['users', 'customers', 'roles', 'permissions', 'role_permissions', 'user_roles', 'project_requests', 'request_drafts', 'request_revisions', 'categories', 'subcategories', 'audit_events'];
        $before = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(static fn ($row): array => (array) $row)->all();
            usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            $before[$table] = $rows;
        }
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('migrations', 34);
        foreach ($before as $table => $rows) {
            $query = DB::table($table);
            if ($table === 'permissions') {
                $query->whereNotIn('code', ['portfolio.read', 'portfolio.manage', 'portfolio.publish', 'contact.read', 'contact.manage', 'contact.redact']);
            }
            if ($table === 'role_permissions') {
                $query->whereIn('permission_id', array_column($before['permissions'], 'id'));
            }
            $after = $query->get()->map(static fn ($row): array => (array) $row)->all();
            usort($after, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            self::assertSame($rows, $after, 'Upgrade changed historical table '.$table);
        }
        self::assertSame(12, DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->whereIn('permissions.code', ['portfolio.read', 'portfolio.manage', 'portfolio.publish', 'contact.read', 'contact.manage', 'contact.redact'])->count());
        $roles = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('permissions.code', 'like', 'portfolio.%')->distinct()->pluck('roles.code')->sort()->values()->all();
        self::assertSame(['administrator', 'super_admin'], $roles);
        $this->assertDatabaseHas('project_requests', ['id' => $request->id, 'customer_id' => $request->customer_id]);
        try {
            DB::transaction(fn () => DB::table('request_revisions')->where('request_id', $request->id)->update(['project_name' => 'Tampered history']));
            self::fail('Historical revision guard disappeared.');
        } catch (QueryException $exception) {
            self::assertNotEmpty($exception->getCode());
        }
        self::assertFalse((bool) DB::scalar("SELECT has_table_privilege('holoul_app','contact_messages','DELETE')"));
        self::assertFalse((bool) DB::scalar("SELECT has_table_privilege('holoul_app','contact_delivery_attempts','UPDATE')"));
        self::assertFalse((bool) DB::scalar("SELECT has_table_privilege('holoul_app','portfolio_publications','UPDATE')"));
        self::assertFalse((bool) DB::scalar("SELECT has_table_privilege('holoul_app','portfolio_public_images','DELETE')"));
    }
}
