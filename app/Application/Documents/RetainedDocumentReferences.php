<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Modules\Documents\Contracts\DocumentReferences;
use App\Modules\ProjectIntake\Actions\IntakeDocumentReferences;
use App\Modules\Projects\Actions\ProjectDocumentReferences;
use App\Modules\Proposals\Actions\ProposalDocumentReferences;

final readonly class RetainedDocumentReferences implements DocumentReferences
{
    public function __construct(private IntakeDocumentReferences $intake, private ProposalDocumentReferences $proposals,
        private ProjectDocumentReferences $projects) {}

    public function hasAny(string $documentId): bool
    {
        return $this->intake->hasAny($documentId) || $this->proposals->hasAny($documentId) || $this->projects->hasAny($documentId);
    }
}
