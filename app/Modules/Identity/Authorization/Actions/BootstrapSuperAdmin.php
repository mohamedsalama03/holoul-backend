<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class BootstrapSuperAdmin
{
    public function __construct(private RoleAuthority $authority, private RecordAuditEvent $audit) {}

    public function handle(User $user, string $requestId): void
    {
        DB::transaction(function () use ($user, $requestId): void {
            $this->authority->lockChanges();
            $staff = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($staff->kind !== 'staff' || ! $staff->enabled) {
                throw new AuthorizationException;
            }

            $roleId = $this->authority->roleId(Role::SuperAdmin);

            if (DB::table('user_roles')->where('role_id', $roleId)->exists()) {
                throw new ConflictHttpException('Initial security administration is already provisioned.');
            }

            DB::table('user_roles')->insert(['user_id' => $staff->id, 'role_id' => $roleId, 'user_kind' => 'staff']);
            $this->audit->handle('identity.super_admin.bootstrapped', 'user', $staff->id, $requestId);
        });
    }
}
