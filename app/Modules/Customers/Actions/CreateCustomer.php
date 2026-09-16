<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Authorization\CustomerOwnership;
use App\Modules\Customers\Data\CustomerProfile;
use App\Modules\Customers\Data\InternationalPhone;
use App\Modules\Customers\Models\Customer;

final readonly class CreateCustomer
{
    public function __construct(private CustomerOwnership $ownership) {}

    public function handle(string $userId, string $phone, string $display): CustomerProfile
    {
        $this->ownership->scope($userId);
        $number = InternationalPhone::parse($phone, $display);
        $customer = new Customer;
        $customer->user_id = $userId;
        $customer->customer_kind = 'customer';
        $customer->phone_e164 = $number->e164;
        $customer->phone_display = $number->display;
        $customer->save();

        return CustomerProfile::fromModel($customer);
    }
}
