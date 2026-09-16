<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Customers\Authorization\CustomerOwnership;
use App\Modules\Customers\Data\CustomerProfile;
use App\Modules\Customers\Data\InternationalPhone;
use App\Modules\Customers\Policies\CustomerPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCustomer
{
    public function __construct(private CustomerOwnership $ownership, private CustomerPolicy $policy, private RecordAuditEvent $audit) {}

    public function handle(string $actorId, string $customerId, string $phone, string $requestId, ?string $parentIdentityId = null): CustomerProfile
    {
        return DB::transaction(function () use ($actorId, $customerId, $phone, $requestId, $parentIdentityId): CustomerProfile {
            $customer = $this->ownership->find($actorId, $customerId, $parentIdentityId);
            $customer = $customer->newQuery()->whereKey($customer->id)->where('user_id', $actorId)->lockForUpdate()->firstOrFail();

            if (! $this->policy->update($actorId, $customer)) {
                throw new AuthorizationException;
            }

            $number = InternationalPhone::parse($phone);
            $customer->phone_e164 = $number->e164;
            $customer->phone_display = $number->display;
            $customer->save();
            $this->audit->handle('customer.profile_updated', 'customer', $customer->id, $requestId, $actorId);

            return CustomerProfile::fromModel($customer);
        });
    }
}
