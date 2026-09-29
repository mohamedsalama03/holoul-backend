<?php

declare(strict_types=1);

namespace App\Modules\Categories\Actions;

use App\Modules\Categories\Data\TaxonomyActor;
use App\Modules\Categories\Models\ProjectCategory;
use App\Modules\Categories\Models\ProjectSubcategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** The approved launch catalogue is additive. Existing names, state and relationships never change. */
final readonly class ImportLaunchTaxonomy
{
    public function __construct(private ManageTaxonomy $taxonomy) {}

    /** @return array<string,mixed> */
    public function handle(?TaxonomyActor $actor, bool $apply): array
    {
        $bytes = file_get_contents(database_path('catalogs/intake-launch-v1.json'));
        if ($bytes === false) {
            throw new RuntimeException('Approved catalogue unavailable.');
        }
        $document = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($document) || ! is_array($document['categories'] ?? null) || count($document['categories']) !== 12) {
            throw new RuntimeException('Invalid approved catalogue.');
        }
        if ($apply && $actor === null) {
            throw new RuntimeException('An authorized audit actor is required.');
        }
        $categories = [];
        $children = 0;
        foreach ($document['categories'] as $raw) {
            $parent = $this->entry($raw);
            if (! is_array($raw) || ! is_array($raw['subcategories'] ?? null)) {
                throw new RuntimeException('Invalid children.');
            }
            $subcategories = [];
            foreach ($raw['subcategories'] as $child) {
                $entry = $this->entry($child);
                if (isset($subcategories[$entry['slug']])) {
                    throw new RuntimeException('Duplicate child slug.');
                }
                $subcategories[$entry['slug']] = $entry;
                $children++;
            }
            if (isset($categories[$parent['slug']])) {
                throw new RuntimeException('Duplicate parent slug.');
            }
            $categories[$parent['slug']] = ['parent' => $parent, 'children' => $subcategories];
        }
        if ($children !== 49) {
            throw new RuntimeException('Expected 49 approved children.');
        }

        return DB::transaction(function () use ($categories, $apply, $actor, $bytes): array {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('categories.intake-launch-v1',0))");
            $created = ['parents' => 0, 'children' => 0];
            $mapping = [];
            $requestId = (string) Str::uuid7();
            foreach ($categories as $group) {
                $expected = $group['parent'];
                $parent = ProjectCategory::query()->where('slug', $expected['slug'])->lockForUpdate()->first();
                if ($parent !== null && ($parent->name !== $expected['name'] || ! $parent->active || $parent->display_order !== $expected['display_order'])) {
                    throw new RuntimeException('Existing parent differs; no overwrite allowed: '.$expected['slug']);
                }
                $id = $parent?->id;
                if ($parent === null) {
                    $created['parents']++;
                    if ($apply) {
                        $id = $this->taxonomy->createCategory($actor, $expected['name'], $expected['slug'], true, $expected['display_order'], $requestId)->id;
                    }
                }
                $mappedChildren = [];
                foreach ($group['children'] as $child) {
                    $existing = $id === null ? null : ProjectSubcategory::query()->where('category_id', $id)->where('slug', $child['slug'])->lockForUpdate()->first();
                    if ($existing !== null && ($existing->name !== $child['name'] || ! $existing->active || $existing->display_order !== $child['display_order'])) {
                        throw new RuntimeException('Existing child differs; no overwrite allowed: '.$expected['slug'].'/'.$child['slug']);
                    }
                    $childId = $existing?->id;
                    if ($existing === null) {
                        $created['children']++;
                        if ($apply && $id !== null) {
                            $childId = $this->taxonomy->createSubcategory($actor, $id, $child['name'], $child['slug'], true, $child['display_order'], $requestId)->id;
                        }
                    }
                    $mappedChildren[] = ['id' => $childId, 'slug' => $child['slug']];
                }
                $mapping[] = ['id' => $id, 'slug' => $expected['slug'], 'children' => $mappedChildren];
            }

            return ['catalog' => 'intake-launch-v1', 'sha256' => hash('sha256', $bytes), 'applied' => $apply,
                'parents' => 12, 'children' => 49, 'missing_before' => $created, 'existing_rows_changed' => 0, 'mapping' => $mapping];
        });
    }

    /** @return array{name:string,slug:string,display_order:int} */
    private function entry(mixed $raw): array
    {
        if (! is_array($raw) || ! is_string($raw['name'] ?? null) || ! is_string($raw['slug'] ?? null) || ! is_int($raw['display_order'] ?? null) || ($raw['active'] ?? null) !== true) {
            throw new RuntimeException('Invalid approved entry.');
        }

        return ['name' => $raw['name'], 'slug' => $raw['slug'], 'display_order' => $raw['display_order']];
    }
}
