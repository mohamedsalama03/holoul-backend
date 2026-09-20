<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string,list<string>> */
    private const array GRANTS = [
        'projects.read' => ['super_admin', 'project_manager', 'business_analyst', 'sales', 'reviewer'],
        'projects.read_all' => ['super_admin'],
        'projects.convert' => ['super_admin', 'project_manager'],
        'projects.manage' => ['super_admin', 'project_manager', 'business_analyst'],
        'projects.transition' => ['super_admin', 'project_manager'],
        'projects.team.manage' => ['super_admin', 'project_manager'],
        'projects.milestones.manage' => ['super_admin', 'project_manager', 'business_analyst'],
        'projects.updates.publish' => ['super_admin', 'project_manager', 'business_analyst'],
        'projects.documents.read' => ['super_admin', 'project_manager', 'business_analyst', 'reviewer'],
        'projects.documents.upload' => ['super_admin', 'project_manager', 'business_analyst'],
        'projects.self.read' => ['customer'],
        'projects.self.confirm' => ['customer'],
        'projects.self.documents.read' => ['customer'],
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
        if (DB::table('projects')->exists()) {
            throw new LogicException('Project history exists. Restore a verified backup instead of downgrading delivery history.');
        }
        $ids = DB::table('permissions')->whereIn('code', array_keys(self::GRANTS))->select('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('code', array_keys(self::GRANTS))->delete();
    }
};
