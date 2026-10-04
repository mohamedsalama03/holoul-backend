<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Documents\Contracts\DocumentInspector;
use App\Modules\Documents\Data\DocumentFormat;
use App\Modules\Documents\Data\InspectionVerdict;

final class DocumentInspectorDouble implements DocumentInspector
{
    public ?string $rejection = null;

    public int $calls = 0;

    public function inspect(mixed $stream, int $size, DocumentFormat $format): InspectionVerdict
    {
        $this->calls++;

        return new InspectionVerdict($this->rejection === null, $this->rejection);
    }
}
