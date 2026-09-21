<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Documents\Contracts\DocumentTextExtractor;
use App\Modules\Documents\Data\DocumentFormat;
use Closure;

/** Source fencing fault injection; real offline parsers have separate integration coverage. */
final class DocumentTextExtractorDouble implements DocumentTextExtractor
{
    public int $calls = 0;

    public string $text = 'Private document requirement text.';

    public ?Closure $afterExtract = null;

    public function extract(mixed $stream, int $size, DocumentFormat $format, int $maxCharacters): string
    {
        $this->calls++;
        ($this->afterExtract ?? static function (): void {})();

        return $this->text;
    }
}
