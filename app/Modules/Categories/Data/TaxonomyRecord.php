<?php

declare(strict_types=1);

namespace App\Modules\Categories\Data;

use App\Modules\Categories\Models\ProjectSubcategory;
use App\Modules\Categories\Models\TaxonomyModel;

final readonly class TaxonomyRecord
{
    public function __construct(
        public string $id,
        public ?string $categoryId,
        public string $name,
        public string $slug,
        public bool $active,
        public int $displayOrder,
        public int $lockVersion,
    ) {}

    public static function fromModel(TaxonomyModel $model): self
    {
        return new self($model->id, $model instanceof ProjectSubcategory ? $model->category_id : null,
            $model->name, $model->slug, $model->active, $model->display_order, $model->lock_version);
    }

    /** @return array{id:string,category_id:?string,name:string,slug:string,active:bool,display_order:int,lock_version:int} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'category_id' => $this->categoryId, 'name' => $this->name, 'slug' => $this->slug,
            'active' => $this->active, 'display_order' => $this->displayOrder, 'lock_version' => $this->lockVersion];
    }
}
