<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Actions;

use App\Modules\Discovery\Contracts\DiscoveryReader;
use App\Modules\ProjectIntake\Contracts\CommercialContext;
use App\Modules\Proposals\Data\ProposalValues;
use App\Modules\Proposals\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ManageProposalDraft
{
    public function __construct(private ProposalStore $store, private DiscoveryReader $discovery) {}

    public function create(CommercialContext $context, ProposalValues $values): Proposal
    {
        $context->staff('proposals.create');
        $context->requireState(['discovery', 'proposal']);
        $baseline = $values->terms['discovery_revision_id'];
        if (! is_string($baseline)) {
            throw new HttpException(422);
        }
        $this->discovery->requireCurrentCompleted($context->requestId, $baseline);
        DB::table('proposal_series')->insertOrIgnore(['request_id' => $context->requestId]);
        $number = DB::scalar('UPDATE proposal_series SET latest_revision_number=latest_revision_number+1 WHERE request_id=? RETURNING latest_revision_number', [$context->requestId]);
        if (! is_int($number)) {
            throw new HttpException(409);
        }
        $proposal = Proposal::query()->forceCreate([...$values->terms, 'request_id' => $context->requestId, 'customer_id' => $context->customerId,
            'revision_number' => $number, 'author_id' => $context->actorId, 'state' => 'draft', 'lock_version' => 1, 'content_version' => 1]);
        $this->children($proposal, $values, $context->actorId);
        $this->store->event($proposal, 'created', $context->actorId, $context->correlationId);

        return $proposal;
    }

    public function update(CommercialContext $context, string $id, ProposalValues $values): Proposal
    {
        $proposal = $this->editable($context, $id);
        $baseline = $values->terms['discovery_revision_id'];
        if (! is_string($baseline)) {
            throw new HttpException(422);
        }
        $this->discovery->requireCurrentCompleted($context->requestId, $baseline);
        $this->invalidate($context, $proposal, $values->terms);
        $this->children($proposal, $values, $context->actorId);
        $this->store->event($proposal, 'updated', $context->actorId, $context->correlationId);

        return $proposal;
    }

    public function editable(CommercialContext $context, string $id): Proposal
    {
        $context->staff('proposals.edit');
        $context->requireState(['discovery', 'proposal']);
        $proposal = $this->store->find($context, $id);
        $this->store->requireLatest($proposal);
        if ($proposal->issued_at !== null) {
            throw new HttpException(409);
        }

        return $proposal;
    }

    /** @param array<string,mixed> $terms */
    public function invalidate(CommercialContext $context, Proposal $proposal, array $terms = []): void
    {
        $approved = $proposal->current_approval_id !== null;
        $proposal->forceFill([...$terms, 'state' => 'draft', 'current_approval_id' => null, 'content_version' => $proposal->content_version + 1,
            'lock_version' => $proposal->lock_version + 1])->save();
        if ($approved) {
            $this->store->event($proposal, 'approval_invalidated', $context->actorId, $context->correlationId);
        }
        DB::table('proposal_contributors')->insertOrIgnore(['proposal_id' => $proposal->id, 'user_id' => $context->actorId]);
    }

    private function children(Proposal $proposal, ProposalValues $values, string $actor): void
    {
        DB::table('proposal_items')->where('proposal_id', $proposal->id)->delete();
        DB::table('proposal_deliverables')->where('proposal_id', $proposal->id)->delete();
        foreach ($values->items as $item) {
            DB::table('proposal_items')->insert([...$item, 'id' => (string) Str::uuid7(), 'proposal_id' => $proposal->id]);
        }
        foreach ($values->deliverables as $index => $description) {
            DB::table('proposal_deliverables')->insert(['id' => (string) Str::uuid7(), 'proposal_id' => $proposal->id, 'position' => $index + 1, 'description' => $description]);
        }
        DB::table('proposal_contributors')->insertOrIgnore(['proposal_id' => $proposal->id, 'user_id' => $actor]);
    }
}
