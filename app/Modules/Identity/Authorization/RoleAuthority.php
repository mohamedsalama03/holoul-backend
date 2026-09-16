<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RoleAuthority
{
    public function allows(User $user, Permission $permission): bool
    {
        return $user->enabled && DB::table('user_roles')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_roles.user_id', $user->id)->where('users.enabled', true)->where('permissions.code', $permission->value)->exists();
    }

    /** @return list<Role> */
    public function roles(string $userId): array
    {
        return array_values(DB::table('roles')->join('user_roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $userId)->orderBy('roles.code')->pluck('roles.code')
            ->map(function (mixed $code): Role {
                if (! is_string($code) || ($role = Role::tryFrom($code)) === null) {
                    throw new RuntimeException('The role catalog is invalid.');
                }

                return $role;
            })->all());
    }

    /** @param list<Role> $roles
     * @return list<string>
     */
    public function permissionsFor(array $roles): array
    {
        return array_values(DB::table('permissions')->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->whereIn('roles.code', array_map(fn (Role $role): string => $role->value, $roles))->distinct()->pluck('permissions.code')
            ->map(function (mixed $code): string {
                if (! is_string($code) || Permission::tryFrom($code) === null) {
                    throw new RuntimeException('The permission catalog is invalid.');
                }

                return $code;
            })->all());
    }

    public function roleId(Role $role): string
    {
        $id = DB::table('roles')->where('code', $role->value)->value('id');

        return is_string($id) ? $id : throw new RuntimeException('The role catalog is unavailable.');
    }

    public function lockChanges(): void
    {
        // One low-volume security mutation lock serializes bootstrap, grants and status changes.
        DB::select("SELECT pg_advisory_xact_lock(hashtextextended('holoul.identity.authorization', 0))");
    }
}
