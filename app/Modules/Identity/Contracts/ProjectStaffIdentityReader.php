<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

interface ProjectStaffIdentityReader
{
    /** Fresh enabled staff permissions, identity locked through the outer transaction. */
    public function forProjectMembership(string $id): ?AuthorizedIdentity;
}
