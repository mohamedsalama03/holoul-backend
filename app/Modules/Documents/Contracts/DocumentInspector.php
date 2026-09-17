<?php

declare(strict_types=1);

namespace App\Modules\Documents\Contracts;

use App\Modules\Documents\Data\DocumentFormat;
use App\Modules\Documents\Data\InspectionVerdict;

interface DocumentInspector
{
    /** @param resource $stream */
    public function inspect(mixed $stream, int $size, DocumentFormat $format): InspectionVerdict;
}
