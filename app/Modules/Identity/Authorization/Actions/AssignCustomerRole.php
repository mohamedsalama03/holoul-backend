<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class AssignCustomerRole
{
    public function __construct(private RoleAuthority $authority, private RecordAuditEvent $audit) {}

    public function handle(string $userId, string $requestId): void
    {
        DB::transaction(function () use ($userId, $requestId): void {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            if ($user->kind !== 'customer' || ! $user->enabled) {
                throw new AuthorizationException;
            }

            $created = DB::table('user_roles')->insertOrIgnore(['user_id' => $userId, 'role_id' => $this->authority->roleId(Role::Customer), 'user_kind' => 'customer']);

            if ($created !== 0) {
                $this->audit->handle('identity.role.customer.granted', 'user', $userId, $requestId, $userId);
            }
        });
    }
}
