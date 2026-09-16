<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Authorization\CustomerOwnership;
use App\Modules\Customers\Data\CustomerProfile;
use App\Modules\Customers\Policies\CustomerPolicy;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ReadCustomer
{
    public function __construct(private CustomerOwnership $ownership, private CustomerPolicy $policy) {}

    public function handle(string $actorId, string $customerId, ?string $parentIdentityId = null): CustomerProfile
    {
        $customer = $this->ownership->find($actorId, $customerId, $parentIdentityId);

        if (! $this->policy->view($actorId, $customer)) {
            throw new AuthorizationException;
        }

        return CustomerProfile::fromModel($customer);
    }
}
