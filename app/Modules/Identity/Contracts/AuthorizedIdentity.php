<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/** Server-created access context; ownership, assignment and workflow policies still apply. */
final readonly class AuthorizedIdentity
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $id,
        public string $kind,
        public bool $verifiedEmail,
        public array $permissions,
    ) {}

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
