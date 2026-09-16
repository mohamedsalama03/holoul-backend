<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Authorization\Data\StaffRecord;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Authorization\StaffAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Security\SessionSecurity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ChangeStaffAuthorization
{
    public function __construct(private StaffAccess $access, private RoleAuthority $authority, private SessionSecurity $sessions, private RecordAuditEvent $audit) {}

    /** @param list<Role> $roles */
    public function handle(Request $request, string $userId, array $roles, bool $enabled): StaffRecord
    {
        $actor = $this->access->requirePermission($request, Permission::ManageStaff, recentPassword: true);

        if (! Str::isUuid($userId)) {
            throw new NotFoundHttpException;
        }

        if ($roles === [] || count($roles) > 7 || count(array_unique(array_map(fn (Role $role): string => $role->value, $roles))) !== count($roles)) {
            throw ValidationException::withMessages(['roles' => 'A unique list of staff roles is required.']);
        }

        if (in_array(Role::Customer, $roles, true)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($request, $userId, $roles, $enabled, $actor): StaffRecord {
            $this->authority->lockChanges();
            User::query()->whereIn('id', [$actor->id, $userId])->orderBy('id')->lockForUpdate()->get();
            $actor = $this->access->requirePermission($request, Permission::ManageStaff, recentPassword: true);
            $subject = User::query()->whereKey($userId)->where('kind', 'staff')->first() ?? throw new NotFoundHttpException;
            $previous = $this->authority->roles($subject->id);
            $actorRoles = $this->authority->roles($actor->id);

            foreach ([...$previous, ...$roles] as $role) {
                if ($role->isSecurityRole() && (! in_array(Role::SuperAdmin, $actorRoles, true) || ! $this->authority->allows($actor, Permission::ManageSecurity))) {
                    throw new AuthorizationException;
                }
            }

            if (array_diff($this->authority->permissionsFor([...$previous, ...$roles]), $this->authority->permissionsFor($actorRoles)) !== []) {
                throw new AuthorizationException;
            }

            if ($subject->enabled && in_array(Role::SuperAdmin, $previous, true) && (! $enabled || ! in_array(Role::SuperAdmin, $roles, true))
                && ! DB::table('users')->join('user_roles', 'user_roles.user_id', '=', 'users.id')
                    ->where('users.enabled', true)->where('users.kind', 'staff')->where('users.id', '<>', $subject->id)
                    ->where('user_roles.role_id', $this->authority->roleId(Role::SuperAdmin))->exists()) {
                throw new ConflictHttpException('An enabled security administrator is required.');
            }

            $added = array_udiff($roles, $previous, fn (Role $a, Role $b): int => strcmp($a->value, $b->value));
            $removed = array_udiff($previous, $roles, fn (Role $a, Role $b): int => strcmp($a->value, $b->value));

            if ($added === [] && $removed === [] && $enabled === $subject->enabled) {
                return StaffRecord::fromUser($subject, $previous);
            }

            DB::table('user_roles')->where('user_id', $subject->id)->delete();

            foreach ($roles as $role) {
                DB::table('user_roles')->insert(['user_id' => $subject->id, 'role_id' => $this->authority->roleId($role), 'user_kind' => 'staff']);
            }

            $subject->enabled = $enabled;
            $subject->save();
            $requestId = $request->attributes->getString('request_id');

            foreach (['granted' => $added, 'revoked' => $removed] as $event => $changedRoles) {
                foreach ($changedRoles as $role) {
                    $this->audit->handle('identity.role.'.$role->value.'.'.$event, 'user', $subject->id, $requestId, $actor->id);
                }
            }

            $this->audit->handle('identity.authorization_changed', 'user', $subject->id, $requestId, $actor->id);
            $this->sessions->revokeAll($subject->id, $requestId, $actor->id);

            return StaffRecord::fromUser($subject, $roles);
        });
    }
}
