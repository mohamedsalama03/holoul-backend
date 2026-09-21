<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Events;

final readonly class RequestChanged
{
    public function __construct(public string $id, public string $event, public int $version,
        public string $customerUserId, public ?string $assignedStaffId, public string $correlation) {}
}
