<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

interface IdentityReader
{
    public function find(string $id): ?IdentityRecord;
}
