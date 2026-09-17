<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string,list<string>> */
    private const array GRANTS = [
        'discovery.read' => ['super_admin', 'project_manager', 'business_analyst', 'sales', 'reviewer'],
        'discovery.manage' => ['super_admin', 'project_manager', 'business_analyst'],
        'discovery.complete' => ['super_admin', 'project_manager', 'business_analyst'],
        'proposals.read' => ['super_admin', 'project_manager', 'business_analyst', 'sales'],
        'proposals.create' => ['super_admin', 'project_manager', 'business_analyst', 'sales'],
        'proposals.edit' => ['super_admin', 'project_manager', 'business_analyst', 'sales'],
        'proposals.approve' => ['super_admin', 'project_manager'],
        'proposals.issue' => ['super_admin', 'project_manager', 'sales'],
        'proposals.withdraw' => ['super_admin', 'project_manager', 'sales'],
        'proposals.self.read' => ['customer'],
        'proposals.self.accept' => ['customer'],
        'proposals.self.decline' => ['customer'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $code => $roles) {
            $id = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $id, 'code' => $code]);
            foreach ($roles as $role) {
                DB::table('role_permissions')->insert(['role_id' => DB::table('roles')->where('code', $role)->sole()->id, 'permission_id' => $id]);
            }
        }
        // Assigned Sales now participates in commercial intake, with no review,
        // assignment, discovery-management or general document grants.
        DB::table('role_permissions')->insert(['role_id' => DB::table('roles')->where('code', 'sales')->sole()->id,
            'permission_id' => DB::table('permissions')->where('code', 'intake.read')->sole()->id]);
    }

    public function down(): void
    {
        if (DB::table('discovery_records')->exists() || DB::table('proposals')->exists()) {
            throw new LogicException('B5 history exists. Restore a verified backup instead of downgrading commercial history.');
        }
        DB::table('role_permissions')->where('role_id', DB::table('roles')->where('code', 'sales')->sole()->id)
            ->where('permission_id', DB::table('permissions')->where('code', 'intake.read')->sole()->id)->delete();
        $ids = DB::table('permissions')->whereIn('code', array_keys(self::GRANTS))->select('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('code', array_keys(self::GRANTS))->delete();
    }
};
