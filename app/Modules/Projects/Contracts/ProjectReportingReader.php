<?php

declare(strict_types=1);

namespace App\Modules\Projects\Contracts;

interface ProjectReportingReader
{
    /** @return array<string,mixed> */
    public function read(string $from, string $until): array;
}
