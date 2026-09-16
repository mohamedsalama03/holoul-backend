<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

interface AuthorizedStaffReader
{
    /** Requires an active transaction; holds the candidate identity lock until its outer commit. */
    public function forIntakeAssignment(string $id): ?AuthorizedIdentity;
}
