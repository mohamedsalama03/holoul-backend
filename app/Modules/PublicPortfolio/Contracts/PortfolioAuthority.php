<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Contracts;

interface PortfolioAuthority
{
    /** Serializes identity/role changes during worker completion; requires a transaction. */
    public function lockManager(string $id): bool;
}
