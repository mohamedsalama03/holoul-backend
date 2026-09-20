<?php

declare(strict_types=1);

namespace App\Modules\Projects\Contracts;

interface ProjectStaffReader
{
    /** Revalidate current enabled staff, relevant role and project access; lock their identity. */
    public function requireAssignable(string $userId, string $role): void;
}
