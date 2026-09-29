<?php

declare(strict_types=1);

namespace Tests\Feature\PublicServices;

use App\Modules\PublicPortfolio\Contracts\ImageProcessor;
use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PortfolioInfrastructureTest extends TestCase
{
    public function test_real_private_versioned_storage_and_isolated_processor_round_trip(): void
    {
        $source = file_get_contents(base_path('tests/Fixtures/portfolio/valid.png'));
        self::assertIsString($source);
        $id = (string) Str::uuid7();
        $storage = app(PortfolioStorage::class);
        try {
            $version = $storage->put($id, 'source', $source);
            self::assertSame($version, $storage->put($id, 'source', $source));
            $bytes = $storage->get($id, 'source', $version, hash('sha256', $source), strlen($source));
            self::assertSame($source, $bytes);
            $converted = app(ImageProcessor::class)->process($bytes, 'image/png');
            self::assertNull($converted->rejection);
            self::assertSame(['card', 'gallery'], array_keys($converted->variants));
            foreach ($converted->variants as $name => $variant) {
                $stored = $storage->put($id, $name, $variant['bytes']);
                self::assertSame($variant['bytes'], $storage->get($id, $name, $stored, hash('sha256', $variant['bytes']), strlen($variant['bytes'])));
                self::assertSame(100, $variant['width']);
                self::assertSame(50, $variant['height']);
            }
            self::assertSame('invalid_image', app(ImageProcessor::class)->process($source, 'image/jpeg')->rejection);
        } finally {
            $storage->purge($id);
        }
    }
}
