<?php

declare(strict_types=1);

namespace App\Modules\Documents\Data;

use DateTimeImmutable;

final readonly class ObjectVersion
{
    public function __construct(
        public string $key,
        public string $versionId,
        public int $size,
        public DateTimeImmutable $lastModified,
    ) {}
}
