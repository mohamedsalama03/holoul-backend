<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Data;

final readonly class LegacyImage
{
    public function __construct(public string $id, public string $path, public string $sha256, public int $size, public string $alt, public int $order) {}
}
