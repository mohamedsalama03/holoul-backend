<?php

declare(strict_types=1);

namespace Tests\Feature\PublicServices;

use App\Application\PublicServices\LegacyPortfolioReader;
use App\Infrastructure\Async\OperationRunner;
use App\Modules\PublicPortfolio\Actions\ManagePortfolio;
use App\Modules\PublicPortfolio\Contracts\ImageProcessor;
use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use App\Modules\PublicPortfolio\Data\PortfolioActor;
use App\Modules\PublicPortfolio\Data\ProcessedImage;
use App\Modules\PublicPortfolio\Models\PortfolioAsset;
use App\Modules\PublicPortfolio\Models\PortfolioProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommercialDatabase;
use Tests\Support\MemoryPortfolioStorage;
use Tests\Support\PublicContentHttp;
use Tests\TestCase;

final class LegacyPortfolioImportTest extends TestCase
{
    use CommercialDatabase,PublicContentHttp;

    public function test_dry_run_resumable_draft_import_preserves_alias_and_source_images_without_automatic_publication(): void
    {
        Queue::fake();
        $this->initializeBrowser();
        $actor = $this->publicContentOperator();
        app()->instance(PortfolioStorage::class, new MemoryPortfolioStorage);
        app()->instance(ImageProcessor::class, new class implements ImageProcessor
        {
            public function process(string $bytes, string $mediaType): ProcessedImage
            {
                return new ProcessedImage(null, ['card' => ['bytes' => 'card', 'width' => 100, 'height' => 50], 'gallery' => ['bytes' => 'gallery', 'width' => 100, 'height' => 50]]);
            }
        });
        $directory = sys_get_temp_dir().'/legacy-import-'.Str::uuid7();
        mkdir($directory, 0700);
        mkdir($directory.'/images', 0700);
        $source = (string) Str::uuid();
        $image = (string) Str::uuid();
        $bytes = 'Synthetic verified bytes';
        $project = ['id' => $source, 'title' => 'Legacy project', 'summary' => 'Legacy synthetic summary', 'description' => 'A sufficiently long synthetic legacy description.',
            'category' => 'web', 'status' => 'published', 'images' => [['id' => $image, 'alt' => 'Synthetic image']]];
        $manifest = ['format' => 1, 'projects' => [$project], 'images' => [['id' => $image, 'sha256' => hash('sha256', $bytes), 'byte_size' => strlen($bytes)]]];
        file_put_contents($directory.'/images/'.$image.'.webp', $bytes);
        file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        try {
            $this->artisan('portfolio:import-legacy', ['manifest' => $directory.'/manifest.json'])->assertSuccessful();
            $this->assertDatabaseCount('portfolio_projects', 0);
            foreach (range(1, 2) as $attempt) {
                $this->artisan('portfolio:import-legacy', ['manifest' => $directory.'/manifest.json', '--apply' => true, '--actor' => $actor->id])->assertSuccessful();
            }
            $this->assertDatabaseCount('portfolio_projects', 1);
            $this->assertDatabaseCount('portfolio_imports', 1);
            $this->assertDatabaseCount('portfolio_assets', 1);
            $record = PortfolioProject::query()->sole();
            self::assertSame($source, $record->public_id);
            self::assertTrue(Str::isUuid($record->id, 7));
            self::assertNull($record->publication_id);
            $mapping = json_decode(DB::table('portfolio_imports')->sole()->image_map, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(PortfolioAsset::query()->sole()->id, $mapping[$image]);
            $this->browser('GET', '/api/v1/public/portfolio/projects/'.$source)->assertNotFound();
            app(OperationRunner::class)->run(PortfolioAsset::query()->sole()->operation_id);
            $record->refresh();
            DB::transaction(fn () => app(ManagePortfolio::class)->publication(new PortfolioActor($actor->id, ['portfolio.publish'], true), $record->id, true,
                '"'.$record->id.':'.$record->lock_version.'"', (string) Str::uuid7(), (string) Str::uuid7()));
            $this->browser('GET', '/api/v1/public/portfolio/projects/'.$source)->assertOk()->assertJsonPath('data.id', $source);
            $manifest['projects'][0]['title'] = 'Changed source';
            file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->withoutExceptionHandling();
            try {
                $this->artisan('portfolio:import-legacy', ['manifest' => $directory.'/manifest.json', '--apply' => true, '--actor' => $actor->id])->run();
                self::fail('Changed source must require review.');
            } catch (HttpException $error) {
                self::assertSame(409, $error->getStatusCode());
            }
            file_put_contents($directory.'/images/'.$image.'.webp', 'changed');
            try {
                app(LegacyPortfolioReader::class)->read($directory.'/manifest.json');
                self::fail('Changed bytes must fail dry run.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Image bytes or path differ from manifest.', $error->getMessage());
            }
        } finally {
            unlink($directory.'/manifest.json');
            unlink($directory.'/images/'.$image.'.webp');
            rmdir($directory.'/images');
            rmdir($directory);
        }
    }
}
