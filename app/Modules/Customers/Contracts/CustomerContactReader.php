<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

interface CustomerContactReader
{
    /** Actor ID must come from server-authenticated context, never an HTTP ownership field. */
    public function currentForIdentity(string $actorId, bool $lock = false): ?CustomerContact;
}
