<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Commercial\CommercialWorkflow;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\AssignRequest;
use App\Modules\ProjectIntake\Actions\TransitionRequest;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use App\Modules\Proposals\Models\Proposal;
use Illuminate\Support\Str;

trait CommercialFixtures
{
    use IntakeFixtures;

    /** @return array{customer:User,author:User,approver:User,request:ProjectRequest} */
    private function commercialFixture(): array
    {
        $customer = $this->intakeCustomer();
        $author = $this->intakeStaff('super_admin');
        $approver = $this->intakeStaff('super_admin');
        $request = $this->createSubmitted($customer);
        $actor = $this->intakeActor($author);
        app(AssignRequest::class)->handle($actor, $request->id, $this->commercialEtag($request), $author->id, (string) Str::uuid7());
        foreach (['review', 'discovery'] as $action) {
            $request->refresh();
            app(TransitionRequest::class)->handle($actor, $request->id, $this->commercialEtag($request), $action, null, (string) Str::uuid7());
        }

        return ['customer' => $customer, 'author' => $author, 'approver' => $approver, 'request' => $request->refresh()];
    }

    private function commercialEtag(ProjectRequest $request): string
    {
        return VersionPrecondition::etag($request->id, $request->lock_version);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function commercialCommand(User $user, ProjectRequest $request, string $operation, string $id = '', array $input = [],
        ?string $etag = null, ?string $key = null, bool $recent = true): array
    {
        $request->refresh();

        return app(CommercialWorkflow::class)->handle($this->intakeActor($user->refresh()), $recent, $request->id, $id, $operation,
            $etag ?? $this->commercialEtag($request), $key ?? (string) Str::uuid7(), $input, (string) Str::uuid7());
    }

    /** @return array<string,mixed> */
    private function commercialRequirements(): array
    {
        return ['requirements' => [['title' => 'Customer portal', 'description' => 'Private structured requirement.',
            'category' => 'functional', 'priority' => 'must', 'notes' => 'Private reviewer notes.', 'status' => 'confirmed']]];
    }

    private function completeDiscovery(User $author, ProjectRequest $request): string
    {
        $result = $this->commercialCommand($author, $request, 'discovery.create', input: ['summary' => 'Discovery baseline.', 'internal_notes' => 'Staff-only notes.']);
        $id = $result['data']['id'];
        $this->commercialCommand($author, $request, 'discovery.requirements', $id, $this->commercialRequirements());
        $this->commercialCommand($author, $request, 'discovery.start', $id);
        $this->commercialCommand($author, $request, 'discovery.complete', $id);

        return $id;
    }

    /** @return array<string,mixed> */
    private function commercialTerms(string $discovery, string $currency = 'LYD'): array
    {
        return ['discovery_revision_id' => $discovery, 'scope_summary' => 'Private issued commercial scope.',
            'timeline' => 'Six weeks from agreed start.', 'commercial_notes' => 'Private commercial conditions.',
            'pricing_mode' => 'items', 'currency' => $currency, 'amount' => $currency === 'LYD' ? '30.369' : '30.36',
            'valid_until' => now()->addDays(30)->utc()->format('Y-m-d\TH:i:s\Z'),
            'items' => [['title' => 'Implementation', 'description' => 'Private line details.', 'quantity' => 3, 'unit_price' => $currency === 'LYD' ? '10.123' : '10.12']],
            'deliverables' => ['Deployed portal', 'Handover documentation']];
    }

    /** @param array{customer:User,author:User,approver:User,request:ProjectRequest} $fixture */
    private function issueProposal(array $fixture, string $currency = 'LYD', ?int $validSeconds = null): Proposal
    {
        $baseline = $this->completeDiscovery($fixture['author'], $fixture['request']);
        $terms = $this->commercialTerms($baseline, $currency);
        if ($validSeconds !== null) {
            $terms['valid_until'] = now()->addSeconds($validSeconds)->utc()->format('Y-m-d\TH:i:s\Z');
        }
        $result = $this->commercialCommand($fixture['author'], $fixture['request'], 'proposal.create', input: $terms);
        $id = $result['data']['id'];
        $this->commercialCommand($fixture['approver'], $fixture['request'], 'proposal.approve', $id);
        $this->commercialCommand($fixture['author'], $fixture['request'], 'proposal.issue', $id);

        return Proposal::query()->findOrFail($id);
    }
}
