<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Actions;

use App\Infrastructure\Money\Money;
use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\ProjectIntake\Contracts\CommercialContext;
use App\Modules\Proposals\Models\Proposal;
use Illuminate\Support\Facades\DB;

final readonly class ReadProposals
{
    public function __construct(private ProposalStore $store, private RecordAuditEvent $audit) {}

    /** @return array<string,mixed> */
    public function detail(CommercialContext $context, string $id): array
    {
        $proposal = $this->store->find($context, $id);
        $this->audit->handle('proposals.viewed', 'proposals.proposal', $id, $context->correlationId, $context->actorId);
        $items = DB::table('proposal_items')->where('proposal_id', $id)->orderBy('position')->get()->map(function (\stdClass $row) use ($proposal): array {
            $unit = is_int($row->unit_price_minor) ? $row->unit_price_minor : throw new \LogicException('Invalid stored money.');
            $total = is_int($row->line_total_minor) ? $row->line_total_minor : throw new \LogicException('Invalid stored money.');

            return ['id' => $row->id, 'position' => $row->position, 'title' => $row->title, 'description' => $row->description, 'quantity' => $row->quantity,
                'unit_price' => Money::fromMinorUnits($unit, $proposal->currency)->decimal(), 'line_total' => Money::fromMinorUnits($total, $proposal->currency)->decimal()];
        })->all();

        return [...$proposal->only(['id', 'request_id', 'revision_number', 'discovery_revision_id', 'state', 'number', 'lock_version', 'content_version',
            'scope_summary', 'timeline', 'commercial_notes', 'pricing_mode', 'currency', 'valid_until', 'issued_at']),
            'amount' => Money::fromMinorUnits($proposal->amount_minor, $proposal->currency)->decimal(), 'internally_approved' => $proposal->current_approval_id !== null,
            'items' => $items, 'deliverables' => DB::table('proposal_deliverables')->where('proposal_id', $id)->orderBy('position')->get(['id', 'position', 'description'])->all(),
            'decisions' => DB::table('proposal_decisions')->where('proposal_id', $id)->orderBy('decided_at')->get(['decision', 'reason', 'decided_at', 'proposal_version'])->all(),
            'document_id' => DB::table('proposal_documents')->where('proposal_id', $id)->value('document_id')];
    }

    /** @return array<string,mixed> */
    public function listing(CommercialContext $context, int $after, int $limit): array
    {
        if ($context->customer) {
            $context->owner('proposals.self.read');
        } else {
            $context->staff('proposals.read');
        }
        $query = Proposal::query()->where('request_id', $context->requestId)->where('customer_id', $context->customerId)->where('revision_number', '>', $after);
        if ($context->customer) {
            $query->whereNotNull('issued_at');
        }
        $rows = $query->orderBy('revision_number')->limit($limit + 1)->get(['id', 'revision_number', 'number', 'state', 'lock_version', 'currency', 'amount_minor', 'valid_until', 'issued_at']);

        return ['data' => $rows->take($limit)->map(fn (Proposal $p): array => [...$p->only(['id', 'revision_number', 'number', 'state', 'lock_version', 'currency', 'valid_until', 'issued_at']),
            'amount' => Money::fromMinorUnits($p->amount_minor, $p->currency)->decimal()])->all(),
            'meta' => ['next_after' => $rows->count() > $limit ? $rows[$limit - 1]?->revision_number : null]];
    }
}
