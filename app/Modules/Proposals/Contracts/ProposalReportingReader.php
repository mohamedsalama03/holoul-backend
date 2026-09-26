<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Contracts;

interface ProposalReportingReader
{
    /** @return array<string,mixed> */
    public function read(string $from, string $until): array;
}
