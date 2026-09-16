<?php

declare(strict_types=1);

namespace App\Modules\Categories\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Categories\Data\TaxonomyActor;
use App\Modules\Categories\Data\TaxonomyChanges;
use App\Modules\Categories\Data\TaxonomyPage;
use App\Modules\Categories\Data\TaxonomyRecord;
use App\Modules\Categories\Models\ProjectCategory;
use App\Modules\Categories\Models\ProjectSubcategory;
use App\Modules\Categories\Models\TaxonomyModel;
use App\Modules\Categories\Policies\TaxonomyPolicy;
use App\Modules\Categories\TaxonomyCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ManageTaxonomy
{
    public function __construct(private TaxonomyPolicy $policy, private TaxonomyCatalog $catalog, private RecordAuditEvent $audit) {}

    public function createCategory(TaxonomyActor $actor, string $name, string $slug, bool $active, int $displayOrder, string $requestId): TaxonomyRecord
    {
        $this->authorize($actor);
        $changes = new TaxonomyChanges($name, $active, $displayOrder);
        $slug = TaxonomyChanges::slug($slug);

        try {
            return DB::transaction(function () use ($actor, $changes, $slug, $requestId): TaxonomyRecord {
                $category = ProjectCategory::query()->create([
                    'name' => $changes->name, 'slug' => $slug, 'active' => $changes->active,
                    'display_order' => $changes->displayOrder, 'lock_version' => 1,
                ]);
                $this->record($actor, $category, 'created', $requestId);

                return TaxonomyRecord::fromModel($category);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'The category machine key is already in use.']);
        }
    }

    public function createSubcategory(TaxonomyActor $actor, string $categoryId, string $name, string $slug, bool $active, int $displayOrder, string $requestId): TaxonomyRecord
    {
        $this->authorize($actor);
        $this->identifier($categoryId);
        $changes = new TaxonomyChanges($name, $active, $displayOrder);
        $slug = TaxonomyChanges::slug($slug);

        try {
            return DB::transaction(function () use ($actor, $categoryId, $changes, $slug, $requestId): TaxonomyRecord {
                $this->lockedCategory($categoryId);
                $subcategory = ProjectSubcategory::query()->create([
                    'category_id' => $categoryId, 'name' => $changes->name, 'slug' => $slug, 'active' => $changes->active,
                    'display_order' => $changes->displayOrder, 'lock_version' => 1,
                ]);
                $this->record($actor, $subcategory, 'created', $requestId);

                return TaxonomyRecord::fromModel($subcategory);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'The subcategory machine key is already in use under this category.']);
        }
    }

    public function updateCategory(TaxonomyActor $actor, string $id, TaxonomyChanges $changes, ?int $expectedVersion, string $requestId): TaxonomyRecord
    {
        $this->authorize($actor);
        $this->identifier($id);

        return DB::transaction(function () use ($actor, $id, $changes, $expectedVersion, $requestId): TaxonomyRecord {
            return $this->update($actor, $this->lockedCategory($id), $changes, $expectedVersion, $requestId);
        });
    }

    public function updateSubcategory(TaxonomyActor $actor, string $id, TaxonomyChanges $changes, ?int $expectedVersion, string $requestId): TaxonomyRecord
    {
        $this->authorize($actor);
        $this->identifier($id);
        $candidate = ProjectSubcategory::query()->select(['id', 'category_id'])->whereKey($id)->first() ?? throw new NotFoundHttpException;

        return DB::transaction(function () use ($actor, $id, $candidate, $changes, $expectedVersion, $requestId): TaxonomyRecord {
            $this->lockedCategory($candidate->category_id);
            $subcategory = ProjectSubcategory::query()->whereKey($id)->where('category_id', $candidate->category_id)
                ->lockForUpdate()->first() ?? throw new NotFoundHttpException;

            return $this->update($actor, $subcategory, $changes, $expectedVersion, $requestId);
        });
    }

    public function categories(TaxonomyActor $actor, int $limit = 25, ?string $after = null): TaxonomyPage
    {
        $this->authorize($actor);

        return $this->catalog->categories(false, $limit, $after);
    }

    public function subcategories(TaxonomyActor $actor, string $categoryId, int $limit = 25, ?string $after = null): TaxonomyPage
    {
        $this->authorize($actor);

        return $this->catalog->subcategories($categoryId, false, $limit, $after);
    }

    public function category(TaxonomyActor $actor, string $id): TaxonomyRecord
    {
        $this->authorize($actor);
        $this->identifier($id);

        return TaxonomyRecord::fromModel(ProjectCategory::query()->whereKey($id)->first() ?? throw new NotFoundHttpException);
    }

    public function subcategory(TaxonomyActor $actor, string $id): TaxonomyRecord
    {
        $this->authorize($actor);
        $this->identifier($id);

        return TaxonomyRecord::fromModel(ProjectSubcategory::query()->whereKey($id)->first() ?? throw new NotFoundHttpException);
    }

    private function update(TaxonomyActor $actor, TaxonomyModel $model, TaxonomyChanges $changes, ?int $expectedVersion, string $requestId): TaxonomyRecord
    {
        if ($expectedVersion === null) {
            throw new HttpException(428);
        }

        if ($model->lock_version !== $expectedVersion) {
            throw new HttpException(412);
        }

        foreach (['name' => $changes->name, 'active' => $changes->active, 'display_order' => $changes->displayOrder] as $field => $value) {
            if ($value !== null) {
                $model->setAttribute($field, $value);
            }
        }

        if (! $model->isDirty()) {
            return TaxonomyRecord::fromModel($model);
        }

        if ($model->lock_version === PHP_INT_MAX) {
            throw new HttpException(409);
        }

        $model->lock_version++;
        $model->save();
        $this->record($actor, $model, 'updated', $requestId);

        return TaxonomyRecord::fromModel($model);
    }

    private function lockedCategory(string $id): ProjectCategory
    {
        return ProjectCategory::query()->whereKey($id)->lockForUpdate()->first() ?? throw new NotFoundHttpException;
    }

    private function authorize(TaxonomyActor $actor): void
    {
        if (! $this->policy->manage($actor)) {
            throw new AuthorizationException;
        }
    }

    private function identifier(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw new NotFoundHttpException;
        }
    }

    private function record(TaxonomyActor $actor, TaxonomyModel $model, string $action, string $requestId): void
    {
        $type = $model instanceof ProjectSubcategory ? 'subcategory' : 'category';
        $this->audit->handle('taxonomy.'.$type.'.'.$action, $type, $model->id, $requestId, $actor->id);
    }
}
