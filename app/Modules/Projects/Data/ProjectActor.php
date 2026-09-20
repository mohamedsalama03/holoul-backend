<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

/** Current identity facts supplied by the authorized application boundary. */
final readonly class ProjectActor
{
    /** @param list<string> $permissions */
    public function __construct(public string $id, public ?string $customerId, public bool $verifiedEmail,
        public bool $recentAuthentication, public array $permissions) {}
}
