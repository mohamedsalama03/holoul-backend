<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Queries;

use App\Modules\ProjectIntake\Contracts\IntakeDocumentReader;
use Illuminate\Support\Facades\DB;

final class ReadIntakeDocuments implements IntakeDocumentReader
{
    public function isHistoricalAttachment(string $requestId, string $documentId): bool
    {
        return DB::table('intake_revision_documents')->where('request_id', $requestId)->where('document_id', $documentId)->exists();
    }
}
