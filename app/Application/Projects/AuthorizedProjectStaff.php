<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Modules\Identity\Contracts\ProjectStaffIdentityReader;
use App\Modules\Projects\Contracts\ProjectStaffReader;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class AuthorizedProjectStaff implements ProjectStaffReader
{
    public function __construct(private ProjectStaffIdentityReader $identities) {}

    public function requireAssignable(string $userId, string $role): void
    {
        $user = $this->identities->forProjectMembership($userId);
        $required = match ($role) {
            'project_manager' => ['projects.read', 'projects.transition', 'projects.team.manage'],
            'business_analyst' => ['projects.read', 'projects.manage'],
            'contributor' => ['projects.read'],
            default => throw new HttpException(422),
        };
        if ($user === null || array_diff($required, $user->permissions) !== []) {
            throw new HttpException(422);
        }
    }
}
