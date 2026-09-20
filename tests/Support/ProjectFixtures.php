<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Projects\ConvertRequest;
use App\Application\Projects\ProjectWorkflow;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Str;

trait ProjectFixtures
{
    use CommercialFixtures;

    private function projectFixture(): array
    {
        $fixture = $this->commercialFixture();
        $proposal = $this->issueProposal($fixture);
        $this->commercialCommand($fixture['customer'], $fixture['request'], 'proposal.accept', $proposal->id);
        $fixture['request']->refresh();
        $result = app(ConvertRequest::class)->handle($this->projectActor($fixture['author']), $fixture['request']->id,
            $this->commercialEtag($fixture['request']), (string) Str::uuid7(), (string) Str::uuid7());

        return [...$fixture, 'proposal' => $proposal->refresh(), 'project' => Project::query()->findOrFail($result['project_id'])];
    }

    private function projectActor(User $user, bool $recent = true): ProjectActor
    {
        $actor = $this->intakeActor($user->refresh());

        return new ProjectActor($actor->id, $actor->customerId, $actor->verifiedEmail, $recent, $actor->permissions);
    }

    private function projectCommand(User $user, Project $project, string $operation, string $id = '', array $input = [],
        ?string $etag = null, ?string $key = null, bool $recent = true): array
    {
        $project->refresh();

        return app(ProjectWorkflow::class)->handle($this->projectActor($user, $recent), $project->id, $id, $operation,
            $etag ?? VersionPrecondition::etag($project->id, $project->lock_version), $key ?? (string) Str::uuid7(), $input, (string) Str::uuid7());
    }
}
