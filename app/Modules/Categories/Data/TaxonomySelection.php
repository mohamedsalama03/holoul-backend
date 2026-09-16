<?php

declare(strict_types=1);

namespace App\Modules\Categories\Data;

/** Only the chosen identifiers and labels cross the module read boundary. */
final readonly class TaxonomySelection
{
    public function __construct(
        public string $categoryId,
        public string $subcategoryId,
        public string $categoryName,
        public string $subcategoryName,
    ) {}
}
