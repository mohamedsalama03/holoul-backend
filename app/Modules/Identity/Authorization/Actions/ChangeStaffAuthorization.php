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
use App\Modules\Identity\Staff\GrantDenied;
use App\Modules\Identity\Staff\LastAdministrator;
use App\Modules\Identity\Staff\StaffFailure;
use App\Modules\Identity\Staff\StaffGrantPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ChangeStaffAuthorization
{
    public function __construct(private StaffAccess $access, private RoleAuthority $authority, private SessionSecurity $sessions, private RecordAuditEvent $audit) {}

    /** @param list<Role> $roles */
    public function handle(Request $request, string $userId, array $roles, bool $enabled, ?int $expectedRevision = null): StaffRecord
    {
        $actor = $this->access->requirePermission($request, Permission::ManageStaff, recentPassword: true);

        if (! Str::isUuid($userId)) {
            throw new NotFoundHttpException;
        }

        if ($roles === [] || count($roles) > 8 || count(array_unique(array_map(fn (Role $role): string => $role->value, $roles))) !== count($roles)) {
            throw ValidationException::withMessages(['roles' => 'A unique list of staff roles is required.']);
        }

        if (in_array(Role::Customer, $roles, true)) {
            throw new GrantDenied('STAFF_CUSTOMER_ROLE_MIX');
        }

        return DB::transaction(function () use ($request, $userId, $roles, $enabled, $actor, $expectedRevision): StaffRecord {
            $this->authority->lockChanges();
            User::query()->whereIn('id', [$actor->id, $userId])->orderBy('id')->lockForUpdate()->get();
            $actor = $this->access->requirePermission($request, Permission::ManageStaff, recentPassword: true);
            $subject = User::query()->whereKey($userId)->where('kind', 'staff')->first() ?? throw new NotFoundHttpException;
            $previous = $this->authority->roles($subject->id);

            app(StaffGrantPolicy::class)->check($actor, [...$previous, ...$roles]);
            if ($expectedRevision !== null && $subject->authorization_revision !== $expectedRevision) {
                throw new StaffFailure(412, 'STALE_VERSION');
            }

            if ($subject->enabled && in_array(Role::SuperAdmin, $previous, true) && (! $enabled || ! in_array(Role::SuperAdmin, $roles, true))
                && ! DB::table('users')->join('user_roles', 'user_roles.user_id', '=', 'users.id')
                    ->where('users.enabled', true)->where('users.kind', 'staff')->where('users.id', '<>', $subject->id)
                    ->where('user_roles.role_id', $this->authority->roleId(Role::SuperAdmin))
                    ->where(function (Builder $usable): void {
                        $usable->whereNull('users.username')->orWhere(function (Builder $direct): void {
                            $direct->whereExists(function (Builder $mfa): void {
                                $mfa->selectRaw('1')->from('identity_mfa')->whereColumn('identity_mfa.user_id', 'users.id')->whereNotNull('confirmed_at');
                            });
                        });
                    })
                    ->whereNotExists(function (Builder $pending): void {
                        $pending->selectRaw('1')->from('identity_staff_invitations')
                            ->whereColumn('accepted_user_id', 'users.id')->whereNull('activated_at');
                    })->exists()) {
                throw new LastAdministrator;
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
            $subject->authorization_revision++;
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
