<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Actions;

use App\Infrastructure\Clock\DatabaseClock;
use App\Modules\Discovery\Contracts\DiscoveryReader;
use App\Modules\ProjectIntake\Contracts\CommercialContext;
use App\Modules\Proposals\Models\Proposal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The outer application workflow owns the request lock and transition. */
final readonly class ProposalLifecycle
{
    public function __construct(private ProposalStore $store, private DiscoveryReader $discovery) {}

    public function approve(CommercialContext $context, string $id): Proposal
    {
        $context->staff('proposals.approve');
        $context->requireState(['discovery', 'proposal']);
        $proposal = $this->store->find($context, $id);
        $this->store->requireLatest($proposal);
        if ($proposal->state !== 'draft') {
            throw new HttpException(409);
        }
        if ($proposal->author_id === $context->actorId || DB::table('proposal_contributors')->where('proposal_id', $id)->where('user_id', $context->actorId)->exists()) {
            throw new AuthorizationException;
        }
        $this->discovery->requireCurrentCompleted($context->requestId, $proposal->discovery_revision_id);
        if ($proposal->valid_until->lessThanOrEqualTo(DatabaseClock::now())) {
            throw new HttpException(409);
        }
        $approval = (string) Str::uuid7();
        DB::table('proposal_approvals')->insert(['id' => $approval, 'proposal_id' => $id, 'content_version' => $proposal->content_version, 'approved_by' => $context->actorId]);
        $proposal->forceFill(['current_approval_id' => $approval, 'state' => 'internally_approved', 'lock_version' => $proposal->lock_version + 1])->save();
        $this->store->event($proposal, 'approved', $context->actorId, $context->correlationId);

        return $proposal;
    }

    public function issue(CommercialContext $context, string $id): Proposal
    {
        $context->staff('proposals.issue');
        $context->requireState(['discovery']);
        $proposal = $this->store->find($context, $id);
        $this->store->requireLatest($proposal);
        $time = DatabaseClock::now();
        if ($proposal->state !== 'internally_approved' || $proposal->current_approval_id === null || $proposal->valid_until->lessThanOrEqualTo($time)
            || Proposal::query()->where('request_id', $context->requestId)->whereIn('state', ['issued', 'accepted'])->exists()) {
            throw new HttpException(409);
        }
        $this->discovery->requireCurrentCompleted($context->requestId, $proposal->discovery_revision_id);
        $sequence = DB::scalar("SELECT nextval('proposal_reference_sequence')");
        if (! is_int($sequence)) {
            throw new HttpException(503);
        }
        $proposal->forceFill(['number' => 'PROP-'.$time->format('Y').'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'state' => 'issued', 'issued_at' => $time, 'issued_by' => $context->actorId, 'lock_version' => $proposal->lock_version + 1])->save();
        $this->store->event($proposal, 'issued', $context->actorId, $context->correlationId);

        return $proposal;
    }

    public function decide(CommercialContext $context, string $id, bool $accept, ?string $reason): Proposal
    {
        $context->owner($accept ? 'proposals.self.accept' : 'proposals.self.decline', true);
        $context->requireState(['proposal']);
        $proposal = $this->store->find($context, $id);
        if ($proposal->state !== 'issued' || $proposal->current_approval_id === null || $proposal->valid_until->lessThanOrEqualTo(DatabaseClock::now())) {
            throw new HttpException(409);
        }
        $decision = $accept ? 'accepted' : 'declined';
        $this->decision($context, $proposal, $decision, $reason);
        $this->finish($proposal, $decision, $context->actorId, $context->correlationId, $reason);

        return $proposal;
    }

    public function close(CommercialContext $context, string $id, bool $supersede, string $reason): Proposal
    {
        $context->staff($supersede ? 'proposals.issue' : 'proposals.withdraw');
        $context->requireState(['proposal']);
        $proposal = $this->store->find($context, $id);
        if ($proposal->state !== 'issued' || $proposal->valid_until->lessThanOrEqualTo(DatabaseClock::now())) {
            throw new HttpException(409);
        }
        $this->finish($proposal, $supersede ? 'superseded' : 'withdrawn', $context->actorId, $context->correlationId, $reason);

        return $proposal;
    }

    public function rescind(CommercialContext $context, string $id, string $reason): Proposal
    {
        $context->owner('proposals.self.accept', true);
        $context->requireState(['approved']);
        $proposal = $this->store->find($context, $id);
        if ($proposal->state !== 'accepted') {
            throw new HttpException(409);
        }
        $this->decision($context, $proposal, 'rescinded', $reason);
        $this->finish($proposal, 'rescinded', $context->actorId, $context->correlationId, $reason);

        return $proposal;
    }

    public function withdrawRequest(CommercialContext $context, ?string $reason): void
    {
        $context->owner('proposals.self.read');
        $proposal = Proposal::query()->where('request_id', $context->requestId)->whereIn('state', ['issued', 'accepted'])->lockForUpdate()->first();
        if ($proposal === null) {
            throw new HttpException(409);
        }
        if ($proposal->state === 'accepted') {
            $context->owner('proposals.self.accept', true);
            $this->decision($context, $proposal, 'rescinded', $reason);
        }
        $this->finish($proposal, $proposal->state === 'accepted' ? 'rescinded' : 'withdrawn', $context->actorId, $context->correlationId, $reason);
    }

    public function expire(string $requestId, string $id, string $correlation): bool
    {
        $proposal = Proposal::query()->where('request_id', $requestId)->whereKey($id)->lockForUpdate()->first();
        if ($proposal === null || $proposal->state !== 'issued' || $proposal->valid_until->greaterThan(DatabaseClock::now())) {
            return false;
        }
        $this->finish($proposal, 'expired', null, $correlation, 'Validity period ended.');

        return true;
    }

    private function decision(CommercialContext $context, Proposal $proposal, string $decision, ?string $reason): void
    {
        DB::table('proposal_decisions')->insert(['id' => (string) Str::uuid7(), 'proposal_id' => $proposal->id, 'request_id' => $context->requestId,
            'customer_id' => $context->customerId, 'decided_by' => $context->actorId, 'decision' => $decision, 'proposal_version' => $proposal->lock_version,
            'reason' => $reason]);
    }

    private function finish(Proposal $proposal, string $state, ?string $actor, string $correlation, ?string $reason): void
    {
        $proposal->forceFill(['state' => $state, 'lock_version' => $proposal->lock_version + 1])->save();
        $this->store->event($proposal, $state, $actor, $correlation, $reason);
    }
}
