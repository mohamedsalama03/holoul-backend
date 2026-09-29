<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Data;

use Illuminate\Auth\Access\AuthorizationException;

final readonly class PortfolioActor
{
    /** @param list<string> $permissions */
    public function __construct(public string $id, private array $permissions, private bool $recentPassword) {}

    public function require(string $permission, bool $sensitive = false): void
    {
        if (! in_array($permission, $this->permissions, true) || (($sensitive || $permission === 'portfolio.publish') && ! $this->recentPassword)) {
            throw new AuthorizationException;
        }
    }
}
