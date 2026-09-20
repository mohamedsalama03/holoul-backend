<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Modules\Projects\Actions\ManageProjectTeam;
use App\Modules\Projects\Actions\ProjectLifecycle;
use App\Modules\Projects\Actions\ProjectRead;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommercialDatabase;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class ProjectLifecycleTest extends TestCase
{
    use CommercialDatabase;
    use ProjectFixtures;

    private const EVIDENCE = ['planning' => 'plan_approved', 'design' => 'design_approved', 'development' => 'delivery_candidate', 'testing' => 'qa_passed', 'deployment' => 'deployment_succeeded'];

    public function test_all_forward_phases_require_evidence_and_completion_requires_current_explicit_owner_confirmation(): void
    {
        $f = $this->projectFixture();
        $actor = $this->projectActor($f['author']);
        $project = $f['project'];
        foreach (self::EVIDENCE as $phase => $kind) {
            self::assertSame($phase, $project->refresh()->state);
            $this->conflict(fn () => $this->command($actor, $project, 'advance'));
            $this->command($actor, $project, 'recordEvidence', ['kind' => $kind, 'summary' => 'Recorded reviewed scope, plan, candidate and release evidence.']);
            if ($phase === 'deployment') {
                $this->conflict(fn () => $this->command($actor, $project, 'advance'));
                $customer = $this->projectActor($f['customer']);
                $notRecent = new ProjectActor($customer->id, $customer->customerId, true, false, $customer->permissions);
                $this->denied(fn () => $this->command($notRecent, $project, 'confirmCompletion'));
                $notVerified = new ProjectActor($customer->id, $customer->customerId, false, true, $customer->permissions);
                $this->denied(fn () => $this->command($notVerified, $project, 'confirmCompletion'));
                $this->denied(fn () => $this->command($actor, $project, 'confirmCompletion'));
                $this->command($customer, $project, 'confirmCompletion');
            }
            $this->command($actor, $project, 'advance');
        }
        self::assertSame('completed', $project->refresh()->state);
        self::assertSame(6, DB::table('project_state_changes')->where('project_id', $project->id)->count());
        self::assertSame(5, DB::table('project_phase_evidence')->where('project_id', $project->id)->count());
        self::assertSame(1, DB::table('project_completion_confirmations')->where('project_id', $project->id)->count());
        $this->assertDatabaseHas('audit_events', ['event_type' => 'projects.completed', 'subject_id' => $project->id]);
        foreach (['advance', 'hold', 'cancel'] as $command) {
            $this->conflict(fn () => $this->command($actor, $project, $command, $this->reason()));
        }
    }

    public function test_hold_resumes_saved_phase_and_invalidates_prior_phase_evidence(): void
    {
        $f = $this->projectFixture();
        $actor = $this->projectActor($f['author']);
        $project = $f['project'];
        $this->command($actor, $project, 'recordEvidence', ['kind' => 'plan_approved', 'summary' => 'Approved scope, team and plan.']);
        $this->command($actor, $project, 'hold', $this->reason());
        self::assertSame('on_hold', $project->refresh()->state);
        self::assertSame('planning', $project->previous_phase);
        $this->conflict(fn () => $this->command($actor, $project, 'advance'));
        $this->conflict(fn () => $this->command($actor, $project, 'hold', $this->reason()));
        $this->command($actor, $project, 'resume', ['reason' => 'Dependency restored', 'conditions' => 'Customer dependency has been verified.']);
        self::assertSame('planning', $project->refresh()->state);
        self::assertNull($project->previous_phase);
        $this->conflict(fn () => $this->command($actor, $project, 'advance'));
        $this->command($actor, $project, 'recordEvidence', ['kind' => 'plan_approved', 'summary' => 'Reapproved scope, team and plan after pause.']);
        $this->command($actor, $project, 'advance');
        self::assertSame('design', $project->refresh()->state);
        $this->assertDatabaseHas('project_updates', ['project_id' => $project->id, 'content' => 'Customer-visible status explanation.']);
    }

    public function test_failbacks_require_new_release_evidence_and_old_customer_acceptance_cannot_complete_new_deployment(): void
    {
        $f = $this->projectFixture();
        $actor = $this->projectActor($f['author']);
        $project = $f['project'];
        $this->reach($actor, $project, 'testing');
        $this->command($actor, $project, 'fail', $this->reason());
        self::assertSame('development', $project->refresh()->state);
        $this->conflict(fn () => $this->command($actor, $project, 'advance'));
        $this->reach($actor, $project, 'deployment');
        $this->command($actor, $project, 'recordEvidence', ['kind' => 'deployment_succeeded', 'summary' => 'Initial successful deployment.']);
        $this->command($this->projectActor($f['customer']), $project, 'confirmCompletion');
        $this->command($actor, $project, 'fail', $this->reason());
        self::assertSame('testing', $project->refresh()->state);
        $this->conflict(fn () => $this->command($actor, $project, 'advance'));
        $this->reach($actor, $project, 'deployment');
        $this->command($actor, $project, 'recordEvidence', ['kind' => 'deployment_succeeded', 'summary' => 'Redeployed new release candidate.']);
        $this->conflict(fn () => $this->command($actor, $project, 'advance'));
        $this->command($this->projectActor($f['customer']), $project, 'confirmCompletion');
        $this->command($actor, $project, 'advance');
        self::assertSame('completed', $project->refresh()->state);
        self::assertSame(2, DB::table('project_completion_confirmations')->where('project_id', $project->id)->count());
    }

    public function test_super_admin_must_be_assigned_manager_and_customer_cannot_transition_even_with_forged_permissions(): void
    {
        $f = $this->projectFixture();
        $project = $f['project'];
        $outsideAdmin = $this->projectActor($f['approver']);
        $this->denied(fn () => $this->command($outsideAdmin, $project, 'hold', $this->reason()));
        $customer = $this->projectActor($f['customer']);
        $forged = new ProjectActor($customer->id, $customer->customerId, true, true, ['projects.self.read', 'projects.transition', 'projects.read_all']);
        $this->denied(fn () => $this->command($forged, $project, 'hold', $this->reason()));
        $actor = $this->projectActor($f['author']);
        $this->command($actor, $project, 'recordEvidence', ['kind' => 'plan_approved', 'summary' => 'Plan approved by assigned manager.']);
        DB::transaction(function () use ($actor, $project, $f): void {
            $project = app(ProjectStore::class)->find($actor, $project->id);
            app(ManageProjectTeam::class)->add($actor, $project, ['staff_id' => $f['approver']->id, 'role' => 'project_manager'], (string) Str::uuid7());
        });
        $this->conflict(fn () => $this->command($actor, $project, 'advance'));
        $this->command($actor, $project, 'recordEvidence', ['kind' => 'plan_approved', 'summary' => 'Explicitly reapproved scope and plan with the new team.']);
        $this->command($actor, $project, 'advance');
        self::assertSame('design', $project->refresh()->state);
        self::assertSame(2, DB::table('project_phase_evidence')->where('project_id', $project->id)->where('kind', 'plan_approved')->count());
    }

    public function test_revised_deployment_evidence_requires_new_confirmation_without_rewriting_prior_evidence(): void
    {
        $f = $this->projectFixture();
        $project = $f['project'];
        $actor = $this->projectActor($f['author']);
        $customer = $this->projectActor($f['customer']);
        $this->reach($actor, $project, 'deployment');
        $this->command($actor, $project, 'recordEvidence', ['kind' => 'deployment_succeeded', 'summary' => 'Initial deployed release.']);
        $this->command($customer, $project, 'confirmCompletion');
        $this->command($actor, $project, 'recordEvidence', ['kind' => 'deployment_succeeded', 'summary' => 'New deployment evidence after another operational check.']);
        $this->conflict(fn () => $this->command($actor, $project, 'advance'));
        $read = app(ProjectRead::class)->detail($customer, $project, (string) Str::uuid7());
        self::assertFalse($read['completion_confirmed']);
        $this->command($customer, $project, 'confirmCompletion');
        $this->command($actor, $project, 'advance');
        self::assertSame('completed', $project->refresh()->state);
        self::assertSame(2, DB::table('project_phase_evidence')->where('project_id', $project->id)->where('kind', 'deployment_succeeded')->count());
        self::assertSame(2, DB::table('project_completion_confirmations')->where('project_id', $project->id)->count());
    }

    public function test_cancellation_preserves_baseline_and_source_history_and_publishes_only_customer_communication(): void
    {
        $f = $this->projectFixture();
        $actor = $this->projectActor($f['author']);
        $project = $f['project'];
        $this->command($actor, $project, 'hold', $this->reason());
        $this->command($actor, $project, 'cancel', ['reason' => 'Private management reason.', 'customer_communication' => 'Cancellation confirmed with the customer.']);
        self::assertSame('cancelled', $project->refresh()->state);
        self::assertNull($project->previous_phase);
        $this->assertDatabaseHas('proposals', ['id' => $project->accepted_proposal_id, 'state' => 'accepted']);
        $this->assertDatabaseHas('project_requests', ['id' => $project->source_request_id, 'state' => 'converted']);
        $feed = app(ProjectRead::class)->updates($this->projectActor($f['customer']), $project, null, 25);
        $json = json_encode($feed);
        self::assertStringContainsString('Cancellation confirmed with the customer.', $json);
        self::assertStringNotContainsString('Private management reason.', $json);
        $this->conflict(fn () => $this->command($actor, $project, 'resume', ['reason' => 'Restart', 'conditions' => 'Ready']));
    }

    private function reach(ProjectActor $actor, Project $project, string $target): void
    {
        while ($project->refresh()->state !== $target) {
            $this->command($actor, $project, 'recordEvidence', ['kind' => self::EVIDENCE[$project->state], 'summary' => 'Explicit successful evidence for this phase.']);
            $this->command($actor, $project, 'advance');
        }
    }

    private function command(ProjectActor $actor, Project $project, string $command, array $input = []): void
    {
        DB::transaction(function () use ($actor, $project, $command, $input): void {
            $record = app(ProjectStore::class)->find($actor, $project->id);
            $lifecycle = app(ProjectLifecycle::class);
            if (in_array($command, ['advance', 'confirmCompletion'], true)) {
                $lifecycle->$command($actor, $record, (string) Str::uuid7());
            } else {
                $lifecycle->$command($actor, $record, $input, (string) Str::uuid7());
            }
        });
        $project->refresh();
    }

    private function reason(): array
    {
        return ['reason' => 'Private delivery reason.', 'customer_communication' => 'Customer-visible status explanation.'];
    }

    private function conflict(callable $action): void
    {
        try {
            $action();
            self::fail('Expected guarded conflict.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            self::fail('Expected permission denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }
}
