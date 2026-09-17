<?php

declare(strict_types=1);

namespace App\Modules\Discovery\Contracts;

interface DiscoveryReader
{
    /** Require the latest signed-off revision for this request, holding its lock. */
    public function requireCurrentCompleted(string $requestId, string $revisionId): void;
}
