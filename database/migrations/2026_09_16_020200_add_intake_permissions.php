<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const array GRANTS = [
        'taxonomy.manage' => ['super_admin', 'administrator'],
        'intake.read' => ['super_admin', 'project_manager', 'business_analyst', 'reviewer'],
        'intake.read_all' => ['super_admin'],
        'intake.assign' => ['super_admin', 'project_manager'],
        'intake.review' => ['super_admin', 'project_manager', 'reviewer'],
        'intake.information' => ['super_admin', 'project_manager', 'business_analyst', 'reviewer'],
        'intake.discovery' => ['super_admin', 'project_manager', 'reviewer'],
        'intake.reject' => ['super_admin', 'project_manager'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $code => $roles) {
            $permissionId = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $permissionId, 'code' => $code]);
            foreach ($roles as $role) {
                $roleId = DB::table('roles')->where('code', $role)->sole()->id;
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('code', array_keys(self::GRANTS))->select('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('code', array_keys(self::GRANTS))->delete();
    }
};
