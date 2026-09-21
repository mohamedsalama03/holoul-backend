<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Events;

final readonly class ProposalChanged
{
    public function __construct(public string $id, public string $requestId, public string $event,
        public int $version, public string $correlation) {}
}
