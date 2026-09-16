<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Data;

final readonly class IntakeActor
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $id,
        public ?string $customerId,
        public bool $verifiedEmail,
        public array $permissions = [],
    ) {}

    public function allows(string $permission): bool
    {
        return $this->customerId === null && in_array($permission, $this->permissions, true);
    }
}
