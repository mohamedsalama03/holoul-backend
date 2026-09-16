<?php

declare(strict_types=1);

namespace App\Modules\Customers\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Contracts\IdentityReader;

final readonly class CustomerPolicy
{
    public function __construct(private IdentityReader $identities) {}

    public function view(string $actorId, Customer $customer): bool
    {
        $actor = $this->identities->find($actorId);

        return $actor !== null && $actor->enabled && $actor->kind === 'customer' && $actor->id === $customer->user_id;
    }

    public function update(string $actorId, Customer $customer): bool
    {
        return $this->view($actorId, $customer);
    }
}
