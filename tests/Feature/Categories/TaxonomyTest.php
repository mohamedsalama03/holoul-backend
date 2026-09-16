<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use App\Modules\Categories\Actions\ManageTaxonomy;
use App\Modules\Categories\Data\TaxonomyActor;
use App\Modules\Categories\Data\TaxonomyChanges;
use App\Modules\Categories\Data\TaxonomyRecord;
use App\Modules\Categories\DatabaseTaxonomyReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class TaxonomyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_creation_has_uuid7_stable_keys_and_safe_transactional_audit(): void
    {
        $category = $this->category('software', 'برمجيات');
        $subcategory = $this->subcategory($category, 'mobile-apps', 'Mobile applications');
        self::assertTrue(Str::isUuid($category->id, 7));
        self::assertTrue(Str::isUuid($subcategory->id, 7));
        self::assertSame($category->id, $subcategory->categoryId);
        self::assertSame(1, $category->lockVersion);
        $this->assertDatabaseHas('audit_events', ['subject_id' => $category->id, 'event_type' => 'taxonomy.category.created']);
        $this->assertDatabaseHas('audit_events', ['subject_id' => $subcategory->id, 'event_type' => 'taxonomy.subcategory.created']);
        $metadata = implode('', DB::table('audit_events')->pluck('metadata')->all());
        self::assertStringNotContainsString('برمجيات', $metadata);
        self::assertStringNotContainsString('Mobile applications', $metadata);
    }

    public function test_management_permission_is_required_for_creates_changes_and_inactive_reads(): void
    {
        $category = $this->category();
        $actor = new TaxonomyActor((string) Str::uuid7(), false);
        foreach ([
            fn () => app(ManageTaxonomy::class)->createCategory($actor, 'Denied', 'denied', true, 0, $this->requestId()),
            fn () => app(ManageTaxonomy::class)->updateCategory($actor, $category->id, new TaxonomyChanges(active: false), 1, $this->requestId()),
            fn () => app(ManageTaxonomy::class)->categories($actor),
            fn () => app(ManageTaxonomy::class)->subcategories($actor, $category->id),
            fn () => app(ManageTaxonomy::class)->category($actor, $category->id),
            fn () => app(ManageTaxonomy::class)->subcategory($actor, (string) Str::uuid7()),
        ] as $action) {
            try {
                $action();
                self::fail('A non-manager accessed taxonomy management.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_admin_detail_reads_include_inactive_records_and_hide_missing_identifiers(): void
    {
        $category = $this->category(active: false);
        $subcategory = $this->subcategory($category, active: false);
        $manager = app(ManageTaxonomy::class);
        self::assertSame($category->toArray(), $manager->category($this->actor(), $category->id)->toArray());
        self::assertSame($subcategory->toArray(), $manager->subcategory($this->actor(), $subcategory->id)->toArray());
        foreach (['category', 'subcategory'] as $method) {
            foreach (['invalid-id', (string) Str::uuid7()] as $id) {
                try {
                    $manager->{$method}($this->actor(), $id);
                    self::fail('A missing taxonomy record was returned.');
                } catch (NotFoundHttpException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function test_customer_lists_hide_inactive_categories_and_subcategories(): void
    {
        $active = $this->category('active');
        $inactive = $this->category('inactive', active: false);
        $chosen = $this->subcategory($active, 'selectable');
        $this->subcategory($active, 'hidden', active: false);
        $this->subcategory($inactive, 'hidden-by-parent');
        $reader = app(DatabaseTaxonomyReader::class);
        self::assertSame([$active->id], array_map(fn (TaxonomyRecord $record): string => $record->id, $reader->categories()->items));
        self::assertSame([$chosen->id], array_map(fn (TaxonomyRecord $record): string => $record->id, $reader->subcategories($active->id)->items));
        self::assertCount(2, app(ManageTaxonomy::class)->categories($this->actor())->items);
        self::assertCount(2, app(ManageTaxonomy::class)->subcategories($this->actor(), $active->id)->items);
        $this->expectException(NotFoundHttpException::class);
        $reader->subcategories($inactive->id);
    }

    public function test_selection_returns_only_matching_active_identifiers_and_current_labels(): void
    {
        $category = $this->category();
        $subcategory = $this->subcategory($category);
        $selection = app(DatabaseTaxonomyReader::class)->selection($category->id, $subcategory->id);
        self::assertSame($category->id, $selection->categoryId);
        self::assertSame($subcategory->id, $selection->subcategoryId);
        self::assertSame($category->name, $selection->categoryName);
        self::assertSame($subcategory->name, $selection->subcategoryName);
        self::assertSame(['categoryId', 'subcategoryId', 'categoryName', 'subcategoryName'], array_keys(get_object_vars($selection)));
        app(ManageTaxonomy::class)->updateCategory($this->actor(), $category->id, new TaxonomyChanges(name: 'Updated label'), 1, $this->requestId());
        self::assertSame($category->name, $selection->categoryName, 'A prior selection snapshot must not mutate with later labels.');
    }

    public function test_subcategory_page_rechecks_parent_after_concurrent_deactivation(): void
    {
        $category = $this->category();
        $this->subcategory($category);
        $deactivated = false;
        DB::listen(function (QueryExecuted $query) use ($category, &$deactivated): void {
            if (! $deactivated && str_starts_with($query->sql, 'select exists') && str_contains($query->sql, '"categories"')) {
                $deactivated = true;
                DB::table('categories')->where('id', $category->id)->update(['active' => false, 'lock_version' => 2]);
            }
        });
        $page = app(DatabaseTaxonomyReader::class)->subcategories($category->id);
        self::assertTrue($deactivated);
        self::assertSame([], $page->items);
    }

    public function test_selection_rejects_a_subcategory_from_another_category(): void
    {
        $category = $this->category();
        $other = $this->category('other');
        $subcategory = $this->subcategory($other);
        $this->expectException(ValidationException::class);
        app(DatabaseTaxonomyReader::class)->selection($category->id, $subcategory->id);
    }

    public function test_selection_rejects_inactive_child_and_then_inactive_parent(): void
    {
        $category = $this->category();
        $subcategory = $this->subcategory($category, active: false);
        $reader = app(DatabaseTaxonomyReader::class);
        try {
            $reader->selection($category->id, $subcategory->id);
            self::fail('An inactive child was selectable.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        app(ManageTaxonomy::class)->updateSubcategory($this->actor(), $subcategory->id, new TaxonomyChanges(active: true), 1, $this->requestId());
        app(ManageTaxonomy::class)->updateCategory($this->actor(), $category->id, new TaxonomyChanges(active: false), 1, $this->requestId());
        $this->expectException(ValidationException::class);
        $reader->selection($category->id, $subcategory->id);
    }

    public function test_locked_selection_cannot_silently_run_outside_a_transaction(): void
    {
        $category = $this->category();
        $subcategory = $this->subcategory($category);
        $this->expectException(LogicException::class);
        app(DatabaseTaxonomyReader::class)->selection($category->id, $subcategory->id, true);
    }

    public function test_missing_and_stale_preconditions_do_not_change_records(): void
    {
        $category = $this->category();
        foreach ([[null, 428], [2, 412]] as [$version, $status]) {
            try {
                app(ManageTaxonomy::class)->updateCategory($this->actor(), $category->id, new TaxonomyChanges(active: false), $version, $this->requestId());
                self::fail('An invalid precondition changed taxonomy.');
            } catch (HttpException $exception) {
                self::assertSame($status, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'active' => true, 'lock_version' => 1]);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'taxonomy.category.updated']);
    }

    public function test_edits_reordering_and_deactivation_advance_versions_and_keep_identity(): void
    {
        $category = $this->category();
        $subcategory = $this->subcategory($category);
        $updated = app(ManageTaxonomy::class)->updateCategory($this->actor(), $category->id,
            new TaxonomyChanges('New category', false, 42), 1, $this->requestId());
        self::assertSame(2, $updated->lockVersion);
        self::assertSame($category->slug, $updated->slug);
        self::assertSame($category->id, $updated->id);
        self::assertSame(42, $updated->displayOrder);
        self::assertFalse($updated->active);
        $child = app(ManageTaxonomy::class)->updateSubcategory($this->actor(), $subcategory->id,
            new TaxonomyChanges('New child', true, 8), 1, $this->requestId());
        self::assertSame(2, $child->lockVersion);
        self::assertSame($category->id, $child->categoryId);
        $this->assertDatabaseCount('subcategories', 1);
        $this->assertDatabaseHas('audit_events', ['subject_id' => $child->id, 'event_type' => 'taxonomy.subcategory.updated']);
    }

    public function test_keyset_pagination_has_stable_tie_breaks_and_never_includes_inactive_rows(): void
    {
        $one = $this->category('one');
        $two = $this->category('two');
        $three = $this->category('three');
        $this->category('hidden', active: false);
        $reader = app(DatabaseTaxonomyReader::class);
        $page = $reader->categories(2);
        self::assertCount(2, $page->items);
        self::assertNotNull($page->nextCursor);
        $last = $reader->categories(2, $page->nextCursor);
        self::assertCount(1, $last->items);
        self::assertNull($last->nextCursor);
        $actual = array_map(fn (TaxonomyRecord $record): string => $record->id, [...$page->items, ...$last->items]);
        $expected = [$one->id, $two->id, $three->id];
        sort($expected);
        self::assertSame($expected, $actual);
    }

    public function test_invalid_cursors_and_unbounded_pages_are_rejected(): void
    {
        $reader = app(DatabaseTaxonomyReader::class);
        foreach ([fn () => $reader->categories(101), fn () => $reader->categories(0), fn () => $reader->categories(25, 'not-a-valid-cursor')] as $action) {
            try {
                $action();
                self::fail('An invalid page input was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_subcategory_slug_uniqueness_is_scoped_to_immutable_parent(): void
    {
        $first = $this->category();
        $other = $this->category('other');
        $this->subcategory($first, 'shared-key');
        $this->subcategory($other, 'shared-key');
        $this->expectException(ValidationException::class);
        $this->subcategory($first, 'shared-key');
    }

    public function test_audit_failure_rolls_back_taxonomy_edit_and_version(): void
    {
        $category = $this->category();
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT taxonomy_test_failure CHECK (event_type <> 'taxonomy.category.updated')");
        try {
            try {
                app(ManageTaxonomy::class)->updateCategory($this->actor(), $category->id, new TaxonomyChanges(active: false), 1, $this->requestId());
                self::fail('A taxonomy change committed without its audit event.');
            } catch (QueryException) {
                $this->assertDatabaseHas('categories', ['id' => $category->id, 'active' => true, 'lock_version' => 1]);
            }
        } finally {
            DB::statement('ALTER TABLE audit_events DROP CONSTRAINT taxonomy_test_failure');
        }
    }

    /** @return iterable<string, array<string, mixed>> */
    public static function protectedMutations(): iterable
    {
        yield 'category slug' => ['categories', ['slug' => 'replacement', 'lock_version' => 2]];
        yield 'category blank name' => ['categories', ['name' => ' ', 'lock_version' => 2]];
        yield 'category negative order' => ['categories', ['display_order' => -1, 'lock_version' => 2]];
        yield 'unversioned category edit' => ['categories', ['active' => false]];
        yield 'subcategory slug' => ['subcategories', ['slug' => 'replacement', 'lock_version' => 2]];
        yield 'subcategory parent' => ['subcategories', ['category_id' => 'other', 'lock_version' => 2]];
        yield 'subcategory negative version' => ['subcategories', ['lock_version' => -1]];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('protectedMutations')]
    public function test_database_rejects_invalid_or_identity_changing_edits(string $table, array $changes): void
    {
        $category = $this->category();
        $subcategory = $this->subcategory($category);
        if (($changes['category_id'] ?? null) === 'other') {
            $changes['category_id'] = $this->category('other')->id;
        }
        $this->expectException(QueryException::class);
        DB::table($table)->where('id', $table === 'categories' ? $category->id : $subcategory->id)->update($changes);
    }

    public function test_database_forbids_deleting_even_unreferenced_taxonomy(): void
    {
        $category = $this->category();
        $this->expectException(QueryException::class);
        DB::table('categories')->where('id', $category->id)->delete();
    }

    public function test_database_forbids_truncating_taxonomy(): void
    {
        $category = $this->category();
        $this->subcategory($category);
        $this->expectException(QueryException::class);
        DB::statement('TRUNCATE subcategories');
    }

    public function test_database_composite_key_rejects_mismatched_selection_independently_of_actions(): void
    {
        $category = $this->category();
        $other = $this->category('other');
        $subcategory = $this->subcategory($category);
        DB::statement('CREATE TABLE taxonomy_selection_probe (category_id uuid NOT NULL, subcategory_id uuid NOT NULL, FOREIGN KEY (subcategory_id, category_id) REFERENCES subcategories(id, category_id))');
        try {
            DB::table('taxonomy_selection_probe')->insert(['category_id' => $category->id, 'subcategory_id' => $subcategory->id]);
            try {
                DB::table('taxonomy_selection_probe')->insert(['category_id' => $other->id, 'subcategory_id' => $subcategory->id]);
                self::fail('PostgreSQL accepted a cross-category selection.');
            } catch (QueryException $exception) {
                self::assertSame('23503', $exception->errorInfo[0]);
            }
        } finally {
            DB::statement('DROP TABLE taxonomy_selection_probe');
        }
    }

    private function category(string $slug = 'software', string $name = 'Software', bool $active = true): TaxonomyRecord
    {
        return app(ManageTaxonomy::class)->createCategory($this->actor(), $name, $slug, $active, 0, $this->requestId());
    }

    private function subcategory(TaxonomyRecord $category, string $slug = 'mobile', string $name = 'Mobile', bool $active = true): TaxonomyRecord
    {
        return app(ManageTaxonomy::class)->createSubcategory($this->actor(), $category->id, $name, $slug, $active, 0, $this->requestId());
    }

    private function actor(): TaxonomyActor
    {
        return new TaxonomyActor((string) Str::uuid7(), true);
    }

    private function requestId(): string
    {
        return (string) Str::uuid7();
    }
}
