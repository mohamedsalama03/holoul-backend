<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use App\Modules\Categories\Actions\ManageTaxonomy;
use App\Modules\Categories\Data\TaxonomyActor;
use App\Modules\Categories\DatabaseTaxonomyReader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class TaxonomyConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_locked_selection_allows_readers_but_blocks_parent_and_child_deactivation_until_commit(): void
    {
        $actor = new TaxonomyActor((string) Str::uuid7(), true);
        $category = app(ManageTaxonomy::class)->createCategory($actor, 'Software', 'software', true, 0, (string) Str::uuid7());
        $subcategory = app(ManageTaxonomy::class)->createSubcategory($actor, $category->id, 'Mobile', 'mobile', true, 0, (string) Str::uuid7());
        Config::set('database.connections.taxonomy_peer', Config::array('database.connections.pgsql'));
        $peer = DB::connection('taxonomy_peer');
        $peer->statement("SET lock_timeout = '150ms'");
        DB::beginTransaction();
        try {
            $selection = app(DatabaseTaxonomyReader::class)->selection($category->id, $subcategory->id, true);
            $peer->beginTransaction();
            self::assertNotNull($peer->table('categories')->where('id', $category->id)->sharedLock()->first());
            self::assertNotNull($peer->table('subcategories')->where('id', $subcategory->id)->sharedLock()->first());
            $peer->commit();

            foreach (['categories' => $category->id, 'subcategories' => $subcategory->id] as $table => $id) {
                try {
                    $peer->table($table)->where('id', $id)->update(['active' => false, 'lock_version' => 2]);
                    self::fail('Deactivation bypassed the submission selection lock.');
                } catch (QueryException $exception) {
                    self::assertSame('55P03', $exception->errorInfo[0]);
                }
            }
            self::assertSame('Software', $selection->categoryName);
            self::assertSame('Mobile', $selection->subcategoryName);
            DB::commit();
            self::assertSame(1, $peer->table('categories')->where('id', $category->id)->update(['active' => false, 'lock_version' => 2]));
            self::assertSame(1, $peer->table('subcategories')->where('id', $subcategory->id)->update(['active' => false, 'lock_version' => 2]));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($peer->transactionLevel() > 0) {
                $peer->rollBack();
            }
            DB::purge('taxonomy_peer');
        }
    }

    public function test_independent_competing_edits_commit_one_version_and_one_update_audit(): void
    {
        $actor = new TaxonomyActor((string) Str::uuid7(), true);
        $category = app(ManageTaxonomy::class)->createCategory($actor, 'Original', 'original', true, 0, (string) Str::uuid7());
        $application = 'taxonomy-proof-'.Str::uuid7();
        $program = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            Illuminate\Support\Facades\DB::select('SELECT set_config(?, ?, false)', ['application_name', $argv[4]]);
            $actor = new App\Modules\Categories\Data\TaxonomyActor($argv[2], true);
            try {
                $app->make(App\Modules\Categories\Actions\ManageTaxonomy::class)->updateCategory(
                    $actor, $argv[1], new App\Modules\Categories\Data\TaxonomyChanges(name: $argv[3]),
                    1, (string) Illuminate\Support\Str::uuid7()
                );
                echo 'accepted';
            } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                if ($exception->getStatusCode() !== 412) { throw $exception; }
                echo 'stale';
            }
            PHP;
        $peers = [];
        DB::beginTransaction();
        try {
            DB::table('categories')->where('id', $category->id)->lockForUpdate()->first();
            for ($index = 0; $index < 2; $index++) {
                $peer = new Process([PHP_BINARY, '-r', $program, '--', $category->id, $actor->id, 'Edited '.$index, $application.'-'.$index], base_path(), timeout: 15);
                $peer->start();
                $peers[] = $peer;
            }
            $deadline = microtime(true) + 8;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'like', $application.'%')->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both independent connections must reach the same category lock.');
            DB::commit();
            $results = [];
            foreach ($peers as $peer) {
                self::assertSame(0, $peer->wait(), $peer->getErrorOutput());
                $results[] = trim($peer->getOutput());
            }
            sort($results);
            self::assertSame(['accepted', 'stale'], $results);
            $this->assertDatabaseHas('categories', ['id' => $category->id, 'lock_version' => 2]);
            self::assertSame(1, DB::table('audit_events')->where('subject_id', $category->id)->where('event_type', 'taxonomy.category.updated')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($peers as $peer) {
                if ($peer->isRunning()) {
                    $peer->stop(0);
                }
            }
        }
    }
}
