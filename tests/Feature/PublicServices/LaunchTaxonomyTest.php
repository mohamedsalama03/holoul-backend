<?php

declare(strict_types=1);

namespace Tests\Feature\PublicServices;

use App\Modules\Categories\Actions\ImportLaunchTaxonomy;
use App\Modules\Categories\Actions\ManageTaxonomy;
use App\Modules\Categories\Data\TaxonomyActor;
use App\Modules\Identity\Authorization\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\CommercialDatabase;
use Tests\Support\PublicContentHttp;
use Tests\TestCase;

final class LaunchTaxonomyTest extends TestCase
{
    use CommercialDatabase, PublicContentHttp;

    public function test_approved_catalogue_is_additive_repeatable_and_does_not_disable_b8(): void
    {
        Queue::fake();
        $this->initializeBrowser();
        $user = $this->publicContentOperator(Role::Administrator);
        $actor = new TaxonomyActor($user->id, true);
        $b8 = app(ManageTaxonomy::class)->createCategory($actor, 'B8 Synthetic Category', 'b8-public-services-fixture', true, 500, (string) Str::uuid7());
        $child = app(ManageTaxonomy::class)->createSubcategory($actor, $b8->id, 'B8 Synthetic Child', 'b8-child', true, 500, (string) Str::uuid7());
        $before = DB::table('categories')->where('id', $b8->id)->first();
        $beforeChild = DB::table('subcategories')->where('id', $child->id)->first();
        $preview = app(ImportLaunchTaxonomy::class)->handle(null, false);
        self::assertSame(['parents' => 12, 'children' => 49], $preview['missing_before']);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('subcategories', 1);
        $this->artisan('taxonomy:prepare-launch', ['--apply' => true, '--actor' => $user->id])->assertSuccessful();
        $this->assertDatabaseCount('categories', 13);
        $this->assertDatabaseCount('subcategories', 50);
        $again = app(ImportLaunchTaxonomy::class)->handle($actor, true);
        self::assertSame(['parents' => 0, 'children' => 0], $again['missing_before']);
        self::assertEquals($before, DB::table('categories')->where('id', $b8->id)->first());
        self::assertEquals($beforeChild, DB::table('subcategories')->where('id', $child->id)->first());
        $this->browser('GET', '/api/v1/intake/categories?limit=50')->assertOk();
        $this->assertDatabaseHas('categories', ['id' => $b8->id, 'active' => true, 'lock_version' => 1]);
    }

    public function test_existing_conflict_aborts_the_whole_import_without_overwriting(): void
    {
        Queue::fake();
        $this->initializeBrowser();
        $user = $this->publicContentOperator();
        $actor = new TaxonomyActor($user->id, true);
        app(ManageTaxonomy::class)->createCategory($actor, 'Different existing category', 'web-development', false, 777, (string) Str::uuid7());
        try {
            app(ImportLaunchTaxonomy::class)->handle($actor, true);
            self::fail('Expected a catalogue conflict.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('no overwrite', $error->getMessage());
        }
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('subcategories', 0);
        $this->assertDatabaseHas('categories', ['slug' => 'web-development', 'name' => 'Different existing category', 'active' => false]);
    }
}
