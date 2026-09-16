<?php

declare(strict_types=1);

namespace App\Modules\Customers\ReadModels;

use App\Modules\Customers\Contracts\CustomerContact;
use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Contracts\IdentityReader;

final readonly class DatabaseCustomerContactReader implements CustomerContactReader
{
    public function __construct(private IdentityReader $identities) {}

    public function currentForIdentity(string $actorId, bool $lock = false): ?CustomerContact
    {
        // Resolve and optionally lock the identity before its customer row.
        $identity = $this->identities->contact($actorId, $lock);
        if ($identity === null || ! $identity->enabled || $identity->kind !== 'customer') {
            return null;
        }
        $query = Customer::query()->where('user_id', $identity->id)->select(['id', 'user_id', 'phone_e164']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $customer = $query->first();

        return $customer === null ? null : new CustomerContact($customer->id, $identity->id,
            $identity->fullName, $identity->email, $customer->phone_e164, $identity->verifiedEmail);
    }
}
