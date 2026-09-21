<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Data\SafeAuditMetadata;
use App\Modules\ProjectIntake\Contracts\CommercialContext;
use App\Modules\Proposals\Events\ProposalChanged;
use App\Modules\Proposals\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ProposalStore
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function find(CommercialContext $context, string $id): Proposal
    {
        if ($context->customer) {
            $context->owner('proposals.self.read');
        } else {
            $context->staff('proposals.read');
        }
        if (! Str::isUuid($id, 7)) {
            throw new HttpException(404);
        }
        $query = Proposal::query()->whereKey($id)->where('request_id', $context->requestId)->where('customer_id', $context->customerId);
        if ($context->customer) {
            $query->whereNotNull('issued_at');
        }

        return $query->lockForUpdate()->first() ?? throw new HttpException(404);
    }

    public function requireLatest(Proposal $proposal): void
    {
        if (! DB::table('proposal_series')->where('request_id', $proposal->request_id)->where('latest_revision_number', $proposal->revision_number)->exists()) {
            throw new HttpException(409);
        }
    }

    public function event(Proposal $proposal, string $event, ?string $actor, string $correlation, ?string $reason = null): void
    {
        DB::table('proposal_events')->insert(['id' => (string) Str::uuid7(), 'proposal_id' => $proposal->id, 'event' => $event,
            'actor_id' => $actor, 'version' => $proposal->lock_version, 'reason' => $reason, 'correlation_id' => $correlation]);
        $this->audit->handle('proposals.'.$event, 'proposals.proposal', $proposal->id, $correlation, $actor,
            new SafeAuditMetadata(['outcome' => 'succeeded']));
        if (in_array($event, ['issued', 'accepted', 'declined'], true)) {
            Event::dispatch(new ProposalChanged($proposal->id, $proposal->request_id, $event, $proposal->lock_version, $correlation));
        }
    }
}
