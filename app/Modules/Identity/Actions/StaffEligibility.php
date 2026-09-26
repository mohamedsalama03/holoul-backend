<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StaffEligibility
{
    /** @return list<string> */
    public static function intakeActions(): array
    {
        return ['intake.assign', 'intake.review', 'intake.information', 'intake.discovery', 'intake.reject',
            'discovery.manage', 'proposals.create', 'proposals.approve', 'proposals.issue'];
    }

    /** @return list<string> */
    public static function projectPermissions(string $role): array
    {
        return match ($role) {
            'project_manager' => ['projects.read', 'projects.transition', 'projects.team.manage'],
            'business_analyst' => ['projects.read', 'projects.manage'],
            'contributor' => ['projects.read'],
            default => throw new HttpException(422),
        };
    }

    /** Internal query for an already authorized resource picker; never a global staff endpoint.
     * @param  list<string>  $all
     * @param  list<string>  $any
     */
    public function query(array $all, array $any = []): Builder
    {
        $query = DB::table('users')->where('kind', 'staff')->where('enabled', true)->select('users.id', 'users.full_name');
        foreach ($all as $permission) {
            $query->whereExists($this->permissionQuery([$permission]));
        }
        if ($any !== []) {
            $query->whereExists($this->permissionQuery($any));
        }

        return $query;
    }

    /** @param list<string> $permissions */
    private function permissionQuery(array $permissions): Builder
    {
        return DB::table('user_roles')->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->selectRaw('1')
            ->whereColumn('user_roles.user_id', 'users.id')->whereIn('permissions.code', $permissions);
    }
}
