<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Actions;

use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\DocumentState;
use App\Modules\ProjectIntake\Contracts\CommercialContext;
use App\Modules\ProjectIntake\Contracts\IntakeDocumentReader;
use App\Modules\Proposals\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ProposalDocuments
{
    public function __construct(private ProposalStore $store, private ManageProposalDraft $drafts, private DocumentService $documents,
        private IntakeDocumentReader $intake) {}

    public function attach(CommercialContext $context, string $id, string $documentId): Proposal
    {
        $context->staff('documents.read');
        $proposal = $this->drafts->editable($context, $id);
        if (! Str::isUuid($documentId, 7) || ! $this->intake->isHistoricalAttachment($context->requestId, $documentId)) {
            throw new HttpException(404);
        }
        if ($this->documents->metadata($this->owner($context), $documentId, true)->state !== DocumentState::Available) {
            throw new HttpException(409);
        }
        $this->drafts->invalidate($context, $proposal);
        DB::table('proposal_documents')->where('proposal_id', $id)->delete();
        DB::table('proposal_documents')->insert(['proposal_id' => $id, 'request_id' => $context->requestId, 'customer_id' => $context->customerId, 'document_id' => $documentId]);
        $this->store->event($proposal, 'document_attached', $context->actorId, $context->correlationId);

        return $proposal;
    }

    public function remove(CommercialContext $context, string $id, string $documentId): Proposal
    {
        $context->staff('documents.read');
        $proposal = $this->drafts->editable($context, $id);
        $this->readable($context, $id, $documentId, false);
        $this->drafts->invalidate($context, $proposal);
        DB::table('proposal_documents')->where('proposal_id', $id)->delete();
        $this->store->event($proposal, 'document_removed', $context->actorId, $context->correlationId);

        return $proposal;
    }

    public function readable(CommercialContext $context, string $id, string $documentId, bool $download): void
    {
        $this->store->find($context, $id);
        if (! $context->customer) {
            $context->staff($download ? 'documents.download' : 'documents.read');
        }
        if (! Str::isUuid($documentId, 7) || ! DB::table('proposal_documents')->where('proposal_id', $id)->where('document_id', $documentId)
            ->where('request_id', $context->requestId)->where('customer_id', $context->customerId)->exists()) {
            throw new HttpException(404);
        }
    }

    public function owner(CommercialContext $context): DocumentOwner
    {
        return new DocumentOwner($context->requestId, $context->customerId, $context->customerUserId, $context->actorId, $context->correlationId);
    }
}
