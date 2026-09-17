<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Discovery\Actions\ManageDiscovery;
use App\Modules\ProjectIntake\Actions\CommercialIntake;
use App\Modules\ProjectIntake\Actions\IntakeStore;
use App\Modules\ProjectIntake\Contracts\CommercialContext;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\Proposals\Actions\ManageProposalDraft;
use App\Modules\Proposals\Actions\ProposalDocuments;
use App\Modules\Proposals\Actions\ProposalLifecycle;
use App\Modules\Proposals\Actions\ProposalReceipts;
use App\Modules\Proposals\Actions\ReadProposals;
use App\Modules\Proposals\Data\ProposalValues;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class CommercialWorkflow
{
    public function __construct(private IntakeStore $intake, private CommercialIntake $transitions,
        private ManageDiscovery $discovery, private ManageProposalDraft $drafts, private ProposalLifecycle $lifecycle,
        private ProposalReceipts $receipts, private ReadProposals $reads, private ProposalDocuments $documents) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function handle(IntakeActor $actor, bool $recent, string $requestId, string $id, string $operation,
        ?string $etag, ?string $key, array $input, string $correlation): array
    {
        return DB::transaction(function () use ($actor, $recent, $requestId, $id, $operation, $etag, $key, $input, $correlation): array {
            $request = $this->intake->find($actor, $requestId, true);
            $context = $this->transitions->context($request, $actor, $correlation, $recent);
            $this->authorize($context, $operation);
            $after = is_int($input['after'] ?? null) ? $input['after'] : 0;
            $limit = is_int($input['limit'] ?? null) ? $input['limit'] : 25;
            if ($after < 0 || $limit < 1 || $limit > 100) {
                throw new HttpException(422);
            }
            $read = match ($operation) {
                'discovery.list' => $this->discovery->listing($context, $after, $limit),
                'discovery.detail' => ['data' => $this->discovery->detail($context, $id)],
                'proposal.list' => $this->reads->listing($context, $after, $limit),
                'proposal.detail' => ['data' => $this->reads->detail($context, $id)],
                default => null,
            };
            if ($read !== null) {
                return [...$read, 'request_version' => $request->lock_version];
            }
            $fingerprint = null;
            if (str_starts_with($operation, 'proposal.')) {
                $fingerprint = $this->receipts->fingerprint($context, $operation, $id, $etag, $key, $input);
                $replay = $this->receipts->replay($context, $fingerprint);
                if ($replay !== null) {
                    return ['data' => $replay, 'request_version' => $replay['request_version']];
                }
            }
            VersionPrecondition::require($etag, $requestId, $request->lock_version);
            if (str_starts_with($operation, 'discovery.')) {
                $revision = match ($operation) {
                    'discovery.create' => $this->discovery->create($context, $input),
                    'discovery.update' => $this->discovery->update($context, $id, $input),
                    'discovery.requirements' => $this->discovery->requirements($context, $id, $input),
                    'discovery.start' => $this->discovery->start($context, $id),
                    'discovery.complete' => $this->discovery->complete($context, $id),
                    default => throw new HttpException(404),
                };
                $this->transitions->change($request, null, $actor->id, $correlation);

                return ['data' => ['id' => $revision->id, 'state' => $revision->state, 'version' => $revision->lock_version, 'revision_number' => $revision->revision_number],
                    'request_version' => $request->lock_version];
            }
            $reason = is_string($input['reason'] ?? null) ? $input['reason'] : null;
            if (in_array($operation, ['proposal.supersede', 'proposal.withdraw', 'proposal.rescind'], true) && ($reason === null || trim($reason) === '' || mb_strlen($reason) > 5000)) {
                throw new HttpException(422);
            }
            $proposal = match ($operation) {
                'proposal.create' => $this->drafts->create($context, ProposalValues::from($input)),
                'proposal.update' => $this->drafts->update($context, $id, ProposalValues::from($input)),
                'proposal.approve' => $this->lifecycle->approve($context, $id),
                'proposal.issue' => $this->lifecycle->issue($context, $id),
                'proposal.accept' => $this->lifecycle->decide($context, $id, true, null),
                'proposal.decline' => $this->lifecycle->decide($context, $id, false, $reason),
                'proposal.supersede' => $this->lifecycle->close($context, $id, true, $reason),
                'proposal.withdraw' => $this->lifecycle->close($context, $id, false, $reason),
                'proposal.rescind' => $this->lifecycle->rescind($context, $id, $reason),
                'proposal.attach_document' => $this->documents->attach($context, $id, is_string($input['document_id'] ?? null) ? $input['document_id'] : ''),
                'proposal.remove_document' => $this->documents->remove($context, $id, is_string($input['document_id'] ?? null) ? $input['document_id'] : ''),
                default => throw new HttpException(404),
            };
            $target = match ($operation) {
                'proposal.issue' => RequestState::Proposal,
                'proposal.accept' => RequestState::Approved,
                'proposal.decline','proposal.supersede','proposal.withdraw','proposal.rescind' => RequestState::Discovery,
                default => null,
            };
            $this->transitions->change($request, $target, $actor->id, $correlation, $reason);
            if ($fingerprint === null) {
                throw new \LogicException('Proposal command requires a receipt.');
            }
            $result = $this->receipts->record($context, $operation, $fingerprint, $proposal, $request->lock_version);

            return ['data' => $result, 'request_version' => $request->lock_version];
        }, 2);
    }

    public function authorize(CommercialContext $context, string $operation): void
    {
        if ($context->customer) {
            $permission = match ($operation) {
                'proposal.list','proposal.detail' => 'proposals.self.read',
                'proposal.accept','proposal.rescind' => 'proposals.self.accept',
                'proposal.decline' => 'proposals.self.decline',
                default => throw new AuthorizationException,
            };
            $context->owner($permission, in_array($operation, ['proposal.accept', 'proposal.decline', 'proposal.rescind'], true));

            return;
        }
        $permission = match ($operation) {
            'discovery.list','discovery.detail' => 'discovery.read',
            'discovery.create','discovery.update','discovery.requirements','discovery.start' => 'discovery.manage',
            'discovery.complete' => 'discovery.complete',
            'proposal.list','proposal.detail' => 'proposals.read', 'proposal.create' => 'proposals.create',
            'proposal.update','proposal.attach_document','proposal.remove_document' => 'proposals.edit', 'proposal.approve' => 'proposals.approve',
            'proposal.issue','proposal.supersede' => 'proposals.issue', 'proposal.withdraw' => 'proposals.withdraw',
            default => throw new AuthorizationException,
        };
        $context->staff($permission);
    }
}
