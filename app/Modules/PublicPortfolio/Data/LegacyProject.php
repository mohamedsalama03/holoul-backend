<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Data;

final readonly class LegacyProject
{
    /** @param list<LegacyImage> $images */
    public function __construct(public string $id, public string $hash, public string $title, public string $summary, public string $description,
        public string $category, public string $sourceStatus, public array $images) {}
}
