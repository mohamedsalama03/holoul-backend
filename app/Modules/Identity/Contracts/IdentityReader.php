<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

interface IdentityReader
{
    public function find(string $id): ?IdentityRecord;

    /** Trusted internal identity ID only. Locking requires an active transaction. */
    public function contact(string $id, bool $lock = false): ?IdentityContact;
}
