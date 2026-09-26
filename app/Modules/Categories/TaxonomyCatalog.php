<?php

declare(strict_types=1);

namespace App\Modules\Categories;

use App\Modules\Categories\Data\TaxonomyPage;
use App\Modules\Categories\Data\TaxonomyRecord;
use App\Modules\Categories\Models\ProjectCategory;
use App\Modules\Categories\Models\ProjectSubcategory;
use App\Modules\Categories\Models\TaxonomyModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Module-internal query implementation; public callers use the reader/actions. */
final class TaxonomyCatalog
{
    public function categories(bool $activeOnly, int $limit, ?string $after): TaxonomyPage
    {
        $query = ProjectCategory::query()->select(['id', 'name', 'slug', 'active', 'display_order', 'lock_version']);

        if ($activeOnly) {
            $query->where('active', true);
        }

        return $this->page($query, $limit, $after);
    }

    public function subcategories(string $categoryId, bool $activeOnly, int $limit, ?string $after): TaxonomyPage
    {
        if (! Str::isUuid($categoryId)) {
            throw new NotFoundHttpException;
        }

        $category = ProjectCategory::query()->whereKey($categoryId);

        if ($activeOnly) {
            $category->where('active', true);
        }

        if (! $category->exists()) {
            throw new NotFoundHttpException;
        }

        $query = ProjectSubcategory::query()->select(['id', 'category_id', 'name', 'slug', 'active', 'display_order', 'lock_version'])
            ->where('category_id', $categoryId);

        if ($activeOnly) {
            // Recheck the parent in the same statement snapshot as its children;
            // the earlier existence query only establishes the 404 boundary.
            $query->where('active', true)->whereIn('category_id', ProjectCategory::query()->select('id')->where('active', true));
        }

        return $this->page($query, $limit, $after);
    }

    /**
     * @template T of TaxonomyModel
     *
     * @param  Builder<T>  $query
     */
    private function page(Builder $query, int $limit, ?string $after): TaxonomyPage
    {
        if ($limit < 1 || $limit > 100) {
            throw ValidationException::withMessages(['limit' => 'Page size must be between 1 and 100.']);
        }

        if ($after !== null) {
            [$order, $id] = $this->cursor($after);
            $query->where(function (Builder $scope) use ($order, $id): void {
                $scope->where('display_order', '>', $order)->orWhere(function (Builder $tie) use ($order, $id): void {
                    $tie->where('display_order', $order)->where('id', '>', $id);
                });
            });
        }

        $models = $query->orderBy('display_order')->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $models->count() > $limit;

        if ($hasMore) {
            $models->pop();
        }

        $last = $models->last();
        $cursor = $hasMore && $last !== null
            ? rtrim(strtr(base64_encode(json_encode([$last->display_order, $last->id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : null;

        return new TaxonomyPage(array_values($models->map(fn (TaxonomyModel $model): TaxonomyRecord => TaxonomyRecord::fromModel($model))->all()), $cursor);
    }

    /** @return array{int, string} */
    private function cursor(string $cursor): array
    {
        $decoded = strlen($cursor) <= 200 ? base64_decode(strtr($cursor, '-_', '+/'), true) : false;

        try {
            $value = $decoded === false ? null : json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $value = null;
        }

        if (! is_array($value) || array_keys($value) !== [0, 1] || ! is_int($value[0]) || $value[0] < 0 || $value[0] > 1_000_000
            || ! is_string($value[1]) || ! Str::isUuid($value[1])) {
            throw ValidationException::withMessages(['cursor' => 'The pagination cursor is invalid.']);
        }

        return [$value[0], $value[1]];
    }
}
