<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

final readonly class CustomerContact
{
    public function __construct(
        public string $customerId,
        public string $userId,
        public string $fullName,
        public string $email,
        public string $phoneE164,
        public bool $verifiedEmail,
    ) {}
}
