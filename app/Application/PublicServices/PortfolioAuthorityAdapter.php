<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Contracts\IdentityReader;
use App\Modules\PublicPortfolio\Contracts\PortfolioAuthority;

final readonly class PortfolioAuthorityAdapter implements PortfolioAuthority
{
    public function __construct(private IdentityReader $identities, private RoleAuthority $roles) {}

    public function lockManager(string $id): bool
    {
        $identity = $this->identities->contact($id, true);

        return $identity !== null && $identity->enabled && $identity->kind === 'staff' && $identity->emailPrerequisiteSatisfied
            && in_array('portfolio.manage', $this->roles->permissionsFor($this->roles->roles($id)), true);
    }
}
