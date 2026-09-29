<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use Closure;
use RuntimeException;

final class MemoryPortfolioStorage implements PortfolioStorage
{
    /** @var array<string,string> */
    public array $objects = [];

    public ?Closure $afterRead = null;

    public ?Closure $afterWrite = null;

    public function put(string $assetId, string $variant, string $bytes): string
    {
        $key = $assetId.'/'.$variant;
        if (isset($this->objects[$key]) && $this->objects[$key] !== $bytes) {
            throw new RuntimeException('Immutable storage conflict');
        }
        $this->objects[$key] = $bytes;
        if ($this->afterWrite !== null) {
            ($this->afterWrite)();
        }

        return hash('sha256', $bytes);
    }

    public function get(string $assetId, string $variant, string $version, string $sha256, int $size): string
    {
        $bytes = $this->objects[$assetId.'/'.$variant] ?? throw new RuntimeException('Missing object');
        if (hash('sha256', $bytes) !== $sha256 || strlen($bytes) !== $size) {
            throw new RuntimeException('Corrupt object');
        }
        if ($this->afterRead !== null) {
            ($this->afterRead)();
        }

        return $bytes;
    }

    public function purge(string $assetId): void
    {
        foreach (['source', 'card', 'gallery'] as $variant) {
            unset($this->objects[$assetId.'/'.$variant]);
        }
    }
}
