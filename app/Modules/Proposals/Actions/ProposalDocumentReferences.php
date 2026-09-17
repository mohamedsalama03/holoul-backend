<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Actions;

use App\Modules\Documents\Contracts\DocumentReferences;
use Illuminate\Support\Facades\DB;

final class ProposalDocumentReferences implements DocumentReferences
{
    public function hasAny(string $documentId): bool
    {
        return DB::table('proposal_documents')->where('document_id', $documentId)->exists();
    }
}
