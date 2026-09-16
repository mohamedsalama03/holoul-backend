<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization\Actions;

use App\Modules\Identity\Authorization\Data\StaffRecord;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Authorization\StaffAccess;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ReadStaffIdentity
{
    public function __construct(private StaffAccess $access, private RoleAuthority $authority) {}

    public function handle(Request $request, string $userId): StaffRecord
    {
        $this->access->requirePermission($request, Permission::ReadStaff);

        if (! Str::isUuid($userId)) {
            throw new NotFoundHttpException;
        }

        $user = User::query()->where('kind', 'staff')->whereKey($userId)->first() ?? throw new NotFoundHttpException;

        return StaffRecord::fromUser($user, $this->authority->roles($user->id));
    }
}
