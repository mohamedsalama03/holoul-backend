<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Documents\Contracts\MalwareScanner;
use App\Modules\Documents\Data\MalwareVerdict;
use App\Modules\Documents\Exceptions\ScannerUnavailable;

final class DocumentScannerDouble implements MalwareScanner
{
    public bool $unavailable = false;

    public MalwareVerdict $verdict = MalwareVerdict::Clean;

    public int $calls = 0;

    public function scan(mixed $stream, int $size): MalwareVerdict
    {
        $this->calls++;
        if ($this->unavailable) {
            throw new ScannerUnavailable;
        }

        return $this->verdict;
    }
}
