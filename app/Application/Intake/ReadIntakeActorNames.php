<?php

declare(strict_types=1);

namespace App\Application\Intake;

use App\Modules\Identity\Queries\ReadIdentityDisplayNames;
use App\Modules\ProjectIntake\Contracts\IntakeActorNames;

final readonly class ReadIntakeActorNames implements IntakeActorNames
{
    public function __construct(private ReadIdentityDisplayNames $names) {}

    public function lookup(array $ids): array
    {
        return $this->names->lookup($ids);
    }
}
