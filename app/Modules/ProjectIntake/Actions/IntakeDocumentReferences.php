<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Actions;

use App\Modules\Documents\Contracts\DocumentReferences;
use Illuminate\Support\Facades\DB;

final class IntakeDocumentReferences implements DocumentReferences
{
    public function hasAny(string $documentId): bool
    {
        return DB::table('intake_draft_documents')->where('document_id', $documentId)->exists()
            || DB::table('intake_revision_documents')->where('document_id', $documentId)->exists();
    }
}
