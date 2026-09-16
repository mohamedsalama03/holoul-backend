<?php

declare(strict_types=1);

namespace App\Modules\Categories;

use App\Modules\Categories\Contracts\TaxonomyReader;
use App\Modules\Categories\Data\TaxonomyPage;
use App\Modules\Categories\Data\TaxonomySelection;
use App\Modules\Categories\Models\ProjectCategory;
use App\Modules\Categories\Models\ProjectSubcategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final readonly class DatabaseTaxonomyReader implements TaxonomyReader
{
    public function __construct(private TaxonomyCatalog $catalog) {}

    public function categories(int $limit = 25, ?string $after = null): TaxonomyPage
    {
        return $this->catalog->categories(true, $limit, $after);
    }

    public function subcategories(string $categoryId, int $limit = 25, ?string $after = null): TaxonomyPage
    {
        return $this->catalog->subcategories($categoryId, true, $limit, $after);
    }

    public function selection(string $categoryId, string $subcategoryId, bool $lock = false): TaxonomySelection
    {
        if (! Str::isUuid($categoryId) || ! Str::isUuid($subcategoryId)) {
            throw $this->invalidSelection();
        }

        if ($lock && DB::transactionLevel() === 0) {
            throw new LogicException('Locked taxonomy selection requires an enclosing transaction.');
        }

        $category = ProjectCategory::query()->select(['id', 'name'])->whereKey($categoryId)->where('active', true);
        $subcategory = ProjectSubcategory::query()->select(['id', 'name'])->whereKey($subcategoryId)
            ->where('category_id', $categoryId)->where('active', true);

        if ($lock) {
            $category->sharedLock();
            $subcategory->sharedLock();
        }

        // Parent before child is also the management action lock order.
        $selectedCategory = $category->first();
        $selectedSubcategory = $selectedCategory === null ? null : $subcategory->first();

        if ($selectedCategory === null || $selectedSubcategory === null) {
            throw $this->invalidSelection();
        }

        return new TaxonomySelection($selectedCategory->id, $selectedSubcategory->id, $selectedCategory->name, $selectedSubcategory->name);
    }

    private function invalidSelection(): ValidationException
    {
        return ValidationException::withMessages(['subcategory_id' => 'An active category and matching active subcategory are required.']);
    }
}
