<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Contracts;

use App\Modules\PublicPortfolio\Data\ProcessedImage;

interface ImageProcessor
{
    public function process(string $bytes, string $mediaType): ProcessedImage;
}
