<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const array GRANTS = ['reporting.read' => ['super_admin', 'administrator', 'project_manager'], 'audit.investigate' => ['super_admin']];

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
        $ids = DB::table('permissions')->whereIn('code', array_keys(self::GRANTS))->select('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('code', array_keys(self::GRANTS))->delete();
    }
};
