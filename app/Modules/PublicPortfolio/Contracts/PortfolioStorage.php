<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Contracts;

interface PortfolioStorage
{
    /** Returns an immutable, verified provider version. */
    public function put(string $assetId, string $variant, string $bytes): string;

    public function get(string $assetId, string $variant, string $version, string $sha256, int $size): string;

    /** Retired asset only; bounded exact namespace cleanup, never a caller-supplied key. */
    public function purge(string $assetId): void;
}
