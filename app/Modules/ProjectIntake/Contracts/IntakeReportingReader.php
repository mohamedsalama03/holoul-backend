<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Contracts;

/** Aggregate-only read contract; from is inclusive, until exclusive, both UTC. */
interface IntakeReportingReader
{
    /** @return array<string,mixed> */
    public function read(string $from, string $until, ?string $categoryId = null, ?string $subcategoryId = null): array;
}
