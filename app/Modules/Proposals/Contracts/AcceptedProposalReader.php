<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Contracts;

interface AcceptedProposalReader
{
    /** Caller holds the source request lock before taking the proposal lock. */
    public function lockAccepted(string $requestId, string $customerId, string $customerUserId): AcceptedProposal;

    /** Immutable customer-appropriate terms; caller has already authorized the Project.
     * @return array<string,mixed>
     */
    public function baseline(string $proposalId, string $decisionId, string $requestId, string $customerId): array;
}
