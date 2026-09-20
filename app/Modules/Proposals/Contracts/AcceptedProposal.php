<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Contracts;

final readonly class AcceptedProposal
{
    public function __construct(public string $id, public string $requestId, public string $customerId,
        public string $decisionId, public int $acceptedVersion) {}
}
