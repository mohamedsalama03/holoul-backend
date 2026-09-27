<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;

final readonly class StaffGrantPolicy
{
    public function __construct(private RoleAuthority $authority) {}

    /** @param list<Role> $roles */
    public function check(User $actor, array $roles): void
    {
        if (! $actor->enabled || $actor->kind !== 'staff'
            || ! $this->authority->allows($actor, Permission::ReadStaff)
            || ! $this->authority->allows($actor, Permission::ManageStaff)) {
            throw new GrantDenied('ROLE_AUTHORITY_EXCEEDED');
        }
        if (in_array(Role::Customer, $roles, true)) {
            throw new GrantDenied('STAFF_CUSTOMER_ROLE_MIX');
        }
        $own = $this->authority->roles($actor->id);
        foreach ($roles as $role) {
            if ($role->isSecurityRole() && (! in_array(Role::SuperAdmin, $own, true)
                || ! $this->authority->allows($actor, Permission::ManageSecurity))) {
                throw new GrantDenied('SECURITY_ROLE_RESTRICTED');
            }
        }
        if (array_diff($this->authority->permissionsFor($roles), $this->authority->permissionsFor($own)) !== []) {
            throw new GrantDenied('ROLE_AUTHORITY_EXCEEDED');
        }
    }

    /** @return list<string> */
    public function assignable(User $actor): array
    {
        $result = [];
        foreach (Role::cases() as $role) {
            if ($role === Role::Customer) {
                continue;
            }
            try {
                $this->check($actor, [$role]);
                $result[] = $role->value;
            } catch (GrantDenied) { /* A hint; command authorization is always fresh. */
            }
        }
        sort($result);

        return $result;
    }
}
