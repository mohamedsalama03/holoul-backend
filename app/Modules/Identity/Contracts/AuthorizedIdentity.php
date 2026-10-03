<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/** Server-created access context; ownership, assignment and workflow policies still apply. */
final readonly class AuthorizedIdentity
{
    /** Admission policy only; this never proves ownership of an email address. */
    public bool $emailPrerequisiteSatisfied;

    /** @param list<string> $permissions */
    public function __construct(
        public string $id,
        public string $kind,
        public bool $verifiedEmail,
        public array $permissions,
        bool $directStaffAccount = false,
    ) {
        $this->emailPrerequisiteSatisfied = $verifiedEmail || $kind === 'customer' || ($kind === 'staff' && $directStaffAccount);
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
