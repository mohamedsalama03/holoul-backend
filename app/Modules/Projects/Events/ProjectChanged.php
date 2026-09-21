<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

final readonly class ProjectChanged
{
    public function __construct(public string $id, public string $event, public int $version,
        public string $customerUserId, public string $correlation) {}
}
