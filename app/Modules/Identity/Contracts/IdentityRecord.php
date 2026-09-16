<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

final readonly class IdentityRecord
{
    public function __construct(public string $id, public string $kind, public bool $enabled) {}
}
