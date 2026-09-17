<?php

declare(strict_types=1);

namespace App\Modules\Documents\Data;

/** Internal storage identity. Never serialize this value into an API response. */
final readonly class StoredObject
{
    public function __construct(
        public string $key,
        public string $versionId,
        public int $size,
        public string $sha256,
        public string $mime,
    ) {}
}
