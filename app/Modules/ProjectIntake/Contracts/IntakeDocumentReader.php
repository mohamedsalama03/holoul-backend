<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Contracts;

interface IntakeDocumentReader
{
    public function isHistoricalAttachment(string $requestId, string $documentId): bool;
}
