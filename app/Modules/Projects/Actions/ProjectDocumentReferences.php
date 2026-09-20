<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Documents\Contracts\DocumentReferences;
use Illuminate\Support\Facades\DB;

final class ProjectDocumentReferences implements DocumentReferences
{
    public function hasAny(string $documentId): bool
    {
        return DB::table('project_document_uploads')->where('document_id', $documentId)->exists()
            || DB::table('project_documents')->where('document_id', $documentId)->exists();
    }
}
