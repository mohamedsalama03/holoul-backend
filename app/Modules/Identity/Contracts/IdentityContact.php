<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/** Contact data for an authorized consumer; never an authentication credential. */
final readonly class IdentityContact
{
    public function __construct(
        public string $id,
        public string $kind,
        public bool $enabled,
        public string $fullName,
        public string $email,
        public bool $verifiedEmail,
    ) {}
}
