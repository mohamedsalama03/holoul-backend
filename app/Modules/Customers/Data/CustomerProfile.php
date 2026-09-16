<?php

declare(strict_types=1);

namespace App\Modules\Customers\Data;

use App\Modules\Customers\Models\Customer;

final readonly class CustomerProfile
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $phoneE164,
        public string $phoneDisplay,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        return new self($customer->id, $customer->user_id, $customer->phone_e164, $customer->phone_display);
    }

    /** @return array{id:string,user_id:string,phone_e164:string,phone_display:string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'user_id' => $this->userId, 'phone_e164' => $this->phoneE164, 'phone_display' => $this->phoneDisplay];
    }
}
