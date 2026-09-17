<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Modules\ProjectIntake\Actions\CommercialIntake;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\Proposals\Actions\ProposalLifecycle;
use App\Modules\Proposals\Models\Proposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ExpireProposals
{
    public function __construct(private CommercialIntake $intake, private ProposalLifecycle $proposals) {}

    public function handle(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Expiry batch must be between 1 and 1000.');
        }
        $candidates = Proposal::query()->where('state', 'issued')->where('valid_until', '<=', DB::raw('clock_timestamp()'))
            ->orderBy('valid_until')->orderBy('id')->limit($limit)->get(['id', 'request_id']);
        $count = 0;
        foreach ($candidates as $candidate) {
            $count += $this->one($candidate->request_id, $candidate->id) ? 1 : 0;
        }

        return $count;
    }

    public function one(string $requestId, string $proposalId): bool
    {
        return DB::transaction(function () use ($requestId, $proposalId): bool {
            $request = ProjectRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            $correlation = (string) Str::uuid7();
            if ($request === null || $request->state !== RequestState::Proposal || ! $this->proposals->expire($requestId, $proposalId, $correlation)) {
                return false;
            }
            $this->intake->change($request, RequestState::Discovery, null, $correlation, 'Validity period ended.');

            return true;
        }, 2);
    }
}
