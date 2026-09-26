<?php

declare(strict_types=1);

namespace App\Modules\Identity\Security;

use App\Modules\Identity\Actions\StaffEligibility;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Contracts\AuthorizedIdentity;
use App\Modules\Identity\Contracts\AuthorizedStaffReader;
use App\Modules\Identity\Contracts\ProjectStaffIdentityReader;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class DatabaseAuthorizedStaffReader implements AuthorizedStaffReader, ProjectStaffIdentityReader
{
    public function __construct(private RoleAuthority $authority) {}

    public function forProjectMembership(string $id): ?AuthorizedIdentity
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Project membership lookup requires a transaction.');
        }
        if (! Str::isUuid($id, 7)) {
            return null;
        }
        $user = User::query()->whereKey($id)->lockForUpdate()->first();
        if ($user === null || ! $user->enabled || $user->kind !== 'staff') {
            return null;
        }
        $permissions = $this->authority->permissionsFor($this->authority->roles($user->id));
        if (! in_array('projects.read', $permissions, true)) {
            return null;
        }
        sort($permissions);

        return new AuthorizedIdentity($user->id, 'staff', $user->email_verified_at !== null, $permissions);
    }

    public function forIntakeAssignment(string $id): ?AuthorizedIdentity
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Assignment identity lookup requires a transaction.');
        }
        if (! Str::isUuid($id)) {
            return null;
        }
        $user = User::query()->whereKey($id)->lockForUpdate()->first();
        if ($user === null || ! $user->enabled || $user->kind !== 'staff') {
            return null;
        }
        $permissions = $this->authority->permissionsFor($this->authority->roles($user->id));
        if (! in_array('intake.read', $permissions, true) || array_intersect($permissions, StaffEligibility::intakeActions()) === []) {
            return null;
        }
        sort($permissions);

        return new AuthorizedIdentity($user->id, $user->kind, $user->email_verified_at !== null, $permissions);
    }
}
