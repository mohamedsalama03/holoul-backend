<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['documents.read', 'documents.download'] as $code) {
            $id = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $id, 'code' => $code]);
            foreach (['super_admin', 'project_manager', 'business_analyst', 'reviewer'] as $role) {
                DB::table('role_permissions')->insert(['role_id' => DB::table('roles')->where('code', $role)->sole()->id, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', ['documents.read', 'documents.download'])->select('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('code', ['documents.read', 'documents.download'])->delete();
    }
};
