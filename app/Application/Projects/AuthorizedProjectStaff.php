<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Modules\Identity\Actions\StaffEligibility;
use App\Modules\Identity\Contracts\ProjectStaffIdentityReader;
use App\Modules\Projects\Contracts\ProjectStaffReader;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class AuthorizedProjectStaff implements ProjectStaffReader
{
    public function __construct(private ProjectStaffIdentityReader $identities) {}

    public function requireAssignable(string $userId, string $role): void
    {
        $user = $this->identities->forProjectMembership($userId);
        $required = StaffEligibility::projectPermissions($role);
        if ($user === null || array_diff($required, $user->permissions) !== []) {
            throw new HttpException(422);
        }
    }
}
