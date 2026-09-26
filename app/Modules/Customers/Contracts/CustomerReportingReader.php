<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

interface CustomerReportingReader
{
    /** @return array<string,mixed> */
    public function read(string $from, string $until): array;
}
