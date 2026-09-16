<?php

declare(strict_types=1);

namespace App\Modules\Categories\Contracts;

use App\Modules\Categories\Data\TaxonomyPage;
use App\Modules\Categories\Data\TaxonomySelection;

interface TaxonomyReader
{
    public function categories(int $limit = 25, ?string $after = null): TaxonomyPage;

    public function subcategories(string $categoryId, int $limit = 25, ?string $after = null): TaxonomyPage;

    public function selection(string $categoryId, string $subcategoryId, bool $lock = false): TaxonomySelection;
}
