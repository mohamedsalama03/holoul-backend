<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Identity\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class CommercialProjectContractTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeBrowser();
    }

    public function test_populated_commercial_and_project_projections_keep_parent_versions_and_customer_visibility(): void
    {
        $fixture = $this->projectFixture();
        $project = $fixture['project'];
        foreach ([
            ['name' => 'Visible first milestone', 'display_order' => 1, 'customer_visible' => true],
            ['name' => 'Internal checkpoint', 'display_order' => 2, 'customer_visible' => false],
            ['name' => 'Visible final milestone', 'display_order' => 3, 'customer_visible' => true],
        ] as $milestone) {
            $this->projectCommand($fixture['author'], $project, 'project.milestone.create', input: [
                ...$milestone, 'description' => 'Contract projection fixture.', 'due_date' => '2030-01-15',
            ]);
        }
        $this->projectCommand($fixture['author'], $project, 'project.update.publish', input: ['content' => 'Customer delivery update.']);
        $this->projectCommand($fixture['author'], $project, 'project.evidence', input: ['kind' => 'plan_approved', 'summary' => 'Scope and team reviewed.']);
        $requestTag = $this->commercialEtag($fixture['request']->refresh());
        $project->refresh();
        $projectTag = VersionPrecondition::etag($project->id, $project->lock_version);
        $staffRequest = '/api/v1/admin/project-requests/'.$fixture['request']->id;
        $staffProject = '/api/v1/admin/projects/'.$project->id;
        $proposal = $fixture['proposal'];

        $this->signInStaff($fixture['author']);
        $this->browser('GET', $staffRequest.'/discovery?limit=1')->assertOk()->assertHeader('ETag', $requestTag)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.state', 'completed')->assertJsonPath('meta.next_after', null);
        $this->browser('GET', $staffRequest.'/discovery/'.$proposal->discovery_revision_id)->assertOk()->assertHeader('ETag', $requestTag)
            ->assertJsonCount(1, 'data.requirements')->assertJsonPath('data.signoff.revision_id', $proposal->discovery_revision_id);
        $this->browser('GET', $staffRequest.'/proposals?limit=1')->assertOk()->assertHeader('ETag', $requestTag)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $proposal->id)->assertJsonPath('meta.next_after', null);
        $this->browser('GET', $staffRequest.'/proposals/'.$proposal->id)->assertOk()->assertHeader('ETag', $requestTag)
            ->assertJsonCount(1, 'data.items')->assertJsonCount(2, 'data.deliverables')->assertJsonPath('data.decisions.0.decision', 'accepted');

        $this->browser('GET', '/api/v1/admin/projects?state=planning&limit=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $project->id)->assertJsonPath('meta.next_after', null);
        $this->browser('GET', $staffProject)->assertOk()->assertHeader('ETag', $projectTag)
            ->assertJsonPath('data.accepted_baseline.amount', '30.369')->assertJsonCount(1, 'data.accepted_baseline.items');
        $staffMilestones = $this->browser('GET', $staffProject.'/milestones?limit=3')->assertOk()->assertHeader('ETag', $projectTag)
            ->assertJsonCount(3, 'data')->assertJsonPath('data.1.customer_visible', false);
        $firstVisible = $staffMilestones->json('data.0.id');
        $lastVisible = $staffMilestones->json('data.2.id');
        $this->browser('GET', $staffProject.'/updates?limit=1')->assertOk()->assertHeader('ETag', $projectTag)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.content', 'Customer delivery update.');
        $this->browser('GET', $staffProject.'/team-members?limit=1')->assertOk()->assertHeader('ETag', $projectTag)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.role', 'project_manager')->assertJsonPath('data.0.active', true);
        $this->browser('GET', $staffProject.'/evidence?limit=1')->assertOk()->assertHeader('ETag', $projectTag)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'plan_approved');
        $this->browser('GET', $staffProject.'/activity?limit=1')->assertOk()->assertHeader('ETag', $projectTag)
            ->assertJsonCount(1, 'data')->assertJsonStructure(['data' => [['event', 'actor_id', 'entity_version', 'created_at']]]);

        $this->initializeBrowser();
        $this->signIn($fixture['customer'])->assertOk();
        $customerRequest = '/api/v1/project-requests/'.$fixture['request']->id;
        $customerProject = '/api/v1/projects/'.$project->id;
        $this->browser('GET', $customerRequest.'/proposals?limit=1')->assertOk()->assertHeader('ETag', $requestTag)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.state', 'accepted');
        $this->browser('GET', $customerRequest.'/proposals/'.$proposal->id)->assertOk()->assertHeader('ETag', $requestTag)
            ->assertJsonCount(1, 'data.decisions')->assertJsonMissingPath('data.internal_notes')->assertJsonMissingPath('data.requirements');
        $this->browser('GET', '/api/v1/projects?state=planning&limit=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $project->id);
        $this->browser('GET', $customerProject)->assertOk()->assertHeader('ETag', $projectTag)
            ->assertJsonPath('data.accepted_baseline.amount', '30.369')->assertJsonMissingPath('data.phase_epoch');
        $page = $this->browser('GET', $customerProject.'/milestones?limit=1')->assertOk()->assertHeader('ETag', $projectTag)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $firstVisible)->assertJsonPath('meta.next_after', $firstVisible)
            ->assertJsonMissingPath('data.0.customer_visible')->assertJsonMissingPath('data.0.responsible_member_id');
        $this->browser('GET', $customerProject.'/milestones?limit=1&after='.$page->json('meta.next_after'))->assertOk()
            ->assertHeader('ETag', $projectTag)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $lastVisible)->assertJsonPath('meta.next_after', null);
        $this->browser('GET', $customerProject.'/updates?limit=1')->assertOk()->assertHeader('ETag', $projectTag)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.content', 'Customer delivery update.');
    }

    private function signInStaff(User $user): void
    {
        $this->signIn($user)->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
    }
}
