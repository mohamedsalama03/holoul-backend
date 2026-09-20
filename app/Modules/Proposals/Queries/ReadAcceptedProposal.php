<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Queries;

use App\Infrastructure\Money\Money;
use App\Modules\Proposals\Contracts\AcceptedProposal;
use App\Modules\Proposals\Contracts\AcceptedProposalReader;
use App\Modules\Proposals\Models\Proposal;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReadAcceptedProposal implements AcceptedProposalReader
{
    public function lockAccepted(string $requestId, string $customerId, string $customerUserId): AcceptedProposal
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Conversion requires an outer transaction.');
        }
        $proposal = Proposal::query()->where('request_id', $requestId)->where('customer_id', $customerId)
            ->where('state', 'accepted')->lockForUpdate()->first() ?? throw new HttpException(409);
        $decision = DB::table('proposal_decisions')->where('proposal_id', $proposal->id)->where('decision', 'accepted')
            ->where('decided_by', $customerUserId)->where('decided_at', '<', $proposal->valid_until)->first();
        if ($decision === null || ! is_string($decision->id) || ! is_int($decision->proposal_version)
            || $decision->proposal_version + 1 !== $proposal->lock_version || $proposal->current_approval_id === null
            || DB::table('proposal_decisions')->where('proposal_id', $proposal->id)->where('decision', 'rescinded')->exists()) {
            throw new HttpException(409);
        }

        return new AcceptedProposal($proposal->id, $requestId, $customerId, $decision->id, $decision->proposal_version);
    }

    /** @return array<string,mixed> */
    public function baseline(string $proposalId, string $decisionId, string $requestId, string $customerId): array
    {
        $proposal = Proposal::query()->whereKey($proposalId)->where('request_id', $requestId)->where('customer_id', $customerId)
            ->where('state', 'accepted')->first() ?? throw new HttpException(409);
        $decision = DB::table('proposal_decisions')->where('id', $decisionId)->where('proposal_id', $proposalId)->where('decision', 'accepted')->first()
            ?? throw new HttpException(409);
        $items = [];
        foreach (DB::table('proposal_items')->where('proposal_id', $proposalId)->orderBy('position')->get() as $row) {
            if (! is_int($row->unit_price_minor) || ! is_int($row->line_total_minor)) {
                throw new LogicException('Invalid stored price.');
            }
            $items[] = ['title' => $row->title, 'description' => $row->description, 'quantity' => $row->quantity,
                'unit_price' => Money::fromMinorUnits($row->unit_price_minor, $proposal->currency)->decimal(),
                'line_total' => Money::fromMinorUnits($row->line_total_minor, $proposal->currency)->decimal()];
        }

        return [...$proposal->only(['number', 'revision_number', 'content_version', 'scope_summary', 'timeline', 'commercial_notes', 'currency']),
            'accepted_version' => $decision->proposal_version, 'accepted_at' => $decision->decided_at,
            'amount' => Money::fromMinorUnits($proposal->amount_minor, $proposal->currency)->decimal(), 'items' => $items,
            'deliverables' => DB::table('proposal_deliverables')->where('proposal_id', $proposalId)->orderBy('position')->pluck('description')->all()];
    }
}
