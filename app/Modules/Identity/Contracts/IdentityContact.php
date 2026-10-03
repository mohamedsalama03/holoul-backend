<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/** Contact data for an authorized consumer; never an authentication credential. */
final readonly class IdentityContact
{
    /** Admission policy only; this never proves ownership of an email address. */
    public bool $emailPrerequisiteSatisfied;

    public function __construct(
        public string $id,
        public string $kind,
        public bool $enabled,
        public string $fullName,
        public string $email,
        public bool $verifiedEmail,
        bool $directStaffAccount = false,
    ) {
        $this->emailPrerequisiteSatisfied = $verifiedEmail || $kind === 'customer' || ($kind === 'staff' && $directStaffAccount);
    }
}
