<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string,list<string>> */
    private const array GRANTS = [
        'ai.self.use' => ['customer'],
        'ai.self.apply' => ['customer'],
        'ai.use' => ['super_admin', 'project_manager', 'business_analyst', 'sales', 'reviewer'],
        'ai.apply' => ['super_admin', 'project_manager', 'business_analyst'],
        'notifications.self.read' => ['customer', 'super_admin', 'administrator', 'project_manager', 'business_analyst', 'sales', 'reviewer', 'support'],
        'notifications.self.manage' => ['customer', 'super_admin', 'administrator', 'project_manager', 'business_analyst', 'sales', 'reviewer', 'support'],
        'notifications.delivery.read' => ['super_admin'],
        'notifications.delivery.replay' => ['super_admin'],
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
    }

    public function down(): void
    {
        if (DB::table('ai_runs')->exists() || DB::table('notifications')->exists()) {
            throw new LogicException('B7 history exists; restore a verified backup instead of downgrading.');
        }
        $ids = DB::table('permissions')->whereIn('code', array_keys(self::GRANTS))->select('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('code', array_keys(self::GRANTS))->delete();
    }
};
