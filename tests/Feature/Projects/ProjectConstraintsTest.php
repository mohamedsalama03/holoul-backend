<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Modules\Projects\Actions\CreateProject;
use App\Modules\Projects\Actions\ManageMilestones;
use App\Modules\Projects\Actions\ProjectLifecycle;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Proposals\Contracts\AcceptedProposalReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommercialDatabase;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class ProjectConstraintsTest extends TestCase
{
    use CommercialDatabase;
    use ProjectFixtures;

    #[DataProvider('immutableColumns')]
    public function test_project_identity_and_exact_baseline_are_immutable_independent_of_application(string $column, mixed $value): void
    {
        $f = $this->projectFixture();
        $this->blocked(fn () => DB::table('projects')->where('id', $f['project']->id)->update([$column => $value, 'lock_version' => 2]));
        self::assertSame(1, $f['project']->refresh()->lock_version);
        self::assertSame('accepted', $f['proposal']->refresh()->state);
    }

    public static function immutableColumns(): array
    {
        return [['source_request_id', '01994aaa-1111-7111-8111-111111111111'], ['accepted_proposal_id', '01994aaa-1111-7111-8111-111111111111'],
            ['accepted_decision_id', '01994aaa-1111-7111-8111-111111111111'], ['accepted_proposal_version', 99],
            ['customer_id', '01994aaa-1111-7111-8111-111111111111'], ['customer_user_id', '01994aaa-1111-7111-8111-111111111111'],
            ['reference', 'PRJ-2026-999999'], ['name', 'Silently replaced baseline project'], ['created_by', '01994aaa-1111-7111-8111-111111111111']];
    }

    public function test_direct_project_status_changes_need_valid_edges_current_evidence_and_matching_history(): void
    {
        $f = $this->projectFixture();
        $project = $f['project'];
        $this->blocked(fn () => DB::table('projects')->where('id', $project->id)->update(['state' => 'completed', 'phase_epoch' => 2, 'lock_version' => 2]));
        $this->blocked(fn () => DB::table('projects')->where('id', $project->id)->update(['state' => 'design', 'phase_epoch' => 2, 'lock_version' => 2]));
        $this->blocked(function () use ($project, $f): void {
            DB::table('projects')->where('id', $project->id)->update(['state' => 'design', 'phase_epoch' => 2, 'lock_version' => 2]);
            DB::table('project_state_changes')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
                'from_state' => 'planning', 'to_state' => 'design', 'from_epoch' => 1, 'to_epoch' => 2,
                'actor_id' => $f['author']->id, 'entity_version' => 2, 'correlation_id' => (string) Str::uuid7()]);
        });
        $this->blocked(fn () => DB::table('projects')->where('id', $project->id)->update(['phase_epoch' => 2, 'lock_version' => 2]));
        $this->blocked(fn () => DB::table('projects')->where('id', $project->id)->update(['lock_version' => 7]));
        self::assertSame('planning', $project->refresh()->state);
        self::assertSame(1, DB::table('project_state_changes')->where('project_id', $project->id)->count());
    }

    public function test_sql_membership_and_milestone_ownership_persona_history_and_state_constraints(): void
    {
        $a = $this->projectFixture();
        $b = $this->projectFixture();
        $project = $a['project'];
        $this->blocked(fn () => DB::table('project_members')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
            'user_id' => $a['customer']->id, 'role' => 'contributor', 'added_by' => $a['author']->id]));
        $this->blocked(fn () => DB::table('project_members')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
            'user_id' => $a['approver']->id, 'role' => 'contributor', 'added_by' => $a['author']->id]));
        $milestone = DB::transaction(fn () => app(ManageMilestones::class)->create($this->projectActor($a['author']), $project,
            ['name' => 'Checkpoint', 'display_order' => 1, 'customer_visible' => false], (string) Str::uuid7()));
        $foreignMember = DB::table('project_members')->where('project_id', $b['project']->id)->value('id');
        $this->blocked(fn () => DB::table('milestones')->where('id', $milestone->id)->update(['project_id' => $b['project']->id, 'lock_version' => 2]));
        $this->blocked(fn () => DB::table('milestones')->where('id', $milestone->id)->update(['responsible_member_id' => $foreignMember, 'lock_version' => 2]));
        $this->blocked(fn () => DB::table('milestones')->where('id', $milestone->id)->update(['state' => 'completed', 'lock_version' => 2]));
        $this->blocked(fn () => DB::table('milestones')->where('id', $milestone->id)->update(['name' => 'No history', 'lock_version' => 2]));
        $this->blocked(fn () => DB::table('milestones')->where('id', $milestone->id)->delete());
    }

    public function test_old_phase_evidence_and_forged_completion_confirmation_are_rejected_in_postgresql(): void
    {
        $f = $this->projectFixture();
        $project = $f['project'];
        $this->blocked(fn () => DB::table('project_phase_evidence')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
            'phase_epoch' => 3, 'phase' => 'planning', 'kind' => 'plan_approved', 'summary' => 'Stale evidence.',
            'recorded_by' => $f['author']->id, 'entity_version' => 1]));
        $this->blocked(fn () => DB::table('project_phase_evidence')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
            'phase_epoch' => 1, 'phase' => 'planning', 'kind' => 'plan_approved', 'summary' => 'Unassigned administrator.',
            'recorded_by' => $f['approver']->id, 'entity_version' => 1]));
        $this->blocked(fn () => DB::table('project_completion_confirmations')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
            'phase_epoch' => 1, 'deployment_evidence_id' => (string) Str::uuid7(), 'confirmed_by' => $f['customer']->id, 'entity_version' => 1]));
    }

    public function test_project_and_source_conversion_must_commit_as_one_pair_in_postgresql(): void
    {
        $f = $this->commercialFixture();
        $proposal = $this->issueProposal($f);
        $this->commercialCommand($f['customer'], $f['request'], 'proposal.accept', $proposal->id);
        $this->blocked(fn () => DB::table('project_requests')->where('id', $f['request']->id)->update(['state' => 'converted']));
        $this->blocked(function () use ($f): void {
            $baseline = app(AcceptedProposalReader::class)->lockAccepted($f['request']->id, $f['request']->customer_id, $f['customer']->id);
            app(CreateProject::class)->handle($this->projectActor($f['author']), $baseline, $f['customer']->id, 'Orphan project', (string) Str::uuid7());
        });
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseHas('project_requests', ['id' => $f['request']->id, 'state' => 'approved']);
    }

    public function test_histories_are_append_only_and_runtime_cannot_modify_or_truncate_them(): void
    {
        $f = $this->projectFixture();
        $project = $f['project'];
        DB::transaction(fn () => app(ProjectLifecycle::class)->recordEvidence($this->projectActor($f['author']), $project,
            ['kind' => 'plan_approved', 'summary' => 'Approved scope team and plan.'], (string) Str::uuid7()));
        foreach (['project_membership_history', 'project_phase_evidence', 'project_state_changes', 'project_activity'] as $table) {
            $this->blocked(fn () => DB::table($table)->where('project_id', $project->id)->delete(), '55000');
            $this->blocked(fn () => DB::statement('TRUNCATE TABLE '.$table.' CASCADE'), '55000');
        }
        DB::statement('SET ROLE holoul_app');
        try {
            foreach (['project_membership_history', 'project_phase_evidence', 'project_completion_confirmations', 'project_state_changes', 'milestone_changes', 'project_updates', 'project_activity'] as $table) {
                self::assertFalse(DB::scalar("SELECT has_table_privilege(current_user, ?, 'UPDATE,DELETE,TRUNCATE')", [$table]));
                $this->blocked(fn () => DB::table($table)->delete(), '42501');
            }
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    public function test_audit_failure_rolls_back_phase_evidence_version_and_activity(): void
    {
        $f = $this->projectFixture();
        $project = $f['project'];
        $count = DB::table('project_activity')->where('project_id', $project->id)->count();
        DB::unprepared("CREATE OR REPLACE FUNCTION test_project_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Injected audit failure' USING ERRCODE='23514'; END; $$; CREATE TRIGGER project_test_audit_failure BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION test_project_audit_failure();");
        try {
            $this->blocked(function () use ($f, $project): void {
                $actor = $this->projectActor($f['author']);
                $locked = app(ProjectStore::class)->find($actor, $project->id);
                app(ProjectLifecycle::class)->recordEvidence($actor, $locked, ['kind' => 'plan_approved', 'summary' => 'Not committed.'], (string) Str::uuid7());
            });
        } finally {
            DB::unprepared('DROP TRIGGER project_test_audit_failure ON audit_events; DROP FUNCTION test_project_audit_failure();');
        }
        self::assertSame(1, $project->refresh()->lock_version);
        self::assertSame(0, DB::table('project_phase_evidence')->where('project_id', $project->id)->count());
        self::assertSame($count, DB::table('project_activity')->where('project_id', $project->id)->count());
    }

    private function blocked(callable $action, string $state = '23514'): void
    {
        try {
            DB::transaction($action);
            self::fail('Expected independent PostgreSQL integrity guard.');
        } catch (\PDOException $e) {
            self::assertSame($state, $e->errorInfo[0]);
        }
    }
}
