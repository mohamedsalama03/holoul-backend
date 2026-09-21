<?php

declare(strict_types=1);

namespace App\Modules\Documents\Contracts;

use App\Modules\Documents\Data\DocumentFormat;

/** Offline processor: never resolves links or communicates with an AI provider. */
interface DocumentTextExtractor
{
    /** @param resource $stream */
    public function extract(mixed $stream, int $size, DocumentFormat $format, int $maxCharacters): string;
}
