<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Modules\Projects\Actions\ManageMilestones;
use App\Modules\Projects\Actions\ManageProjectTeam;
use App\Modules\Projects\Actions\ProjectRead;
use App\Modules\Projects\Actions\ProjectStore;
use App\Modules\Projects\Actions\PublishProjectUpdate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommercialDatabase;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class ProjectTeamMilestonesTest extends TestCase
{
    use CommercialDatabase;
    use ProjectFixtures;

    public function test_team_changes_preserve_history_and_require_a_remaining_manager_and_current_enabled_staff(): void
    {
        $f = $this->projectFixture();
        $actor = $this->projectActor($f['author']);
        $project = $f['project'];
        $original = DB::table('project_members')->where('project_id', $project->id)->value('id');
        $this->rejected(fn () => DB::transaction(fn () => app(ManageProjectTeam::class)->remove($actor, $project, $original, (string) Str::uuid7())), 409);
        $new = DB::transaction(fn () => app(ManageProjectTeam::class)->add($actor, $project, ['staff_id' => $f['approver']->id, 'role' => 'project_manager'], (string) Str::uuid7()));
        $this->assertDatabaseHas('project_members', ['id' => $new, 'active' => true, 'role' => 'project_manager']);
        DB::transaction(fn () => app(ManageProjectTeam::class)->remove($actor, $project, $new, (string) Str::uuid7()));
        $this->assertDatabaseHas('project_members', ['id' => $new, 'active' => false]);
        self::assertSame(['added', 'removed'], DB::table('project_membership_history')->where('member_id', $new)->orderBy('created_at')->pluck('event')->all());
        $this->rejected(fn () => DB::transaction(fn () => app(ManageProjectTeam::class)->add($actor, $project,
            ['staff_id' => $f['customer']->id, 'role' => 'contributor'], (string) Str::uuid7())));
        $f['approver']->forceFill(['enabled' => false])->save();
        $this->rejected(fn () => DB::transaction(fn () => app(ManageProjectTeam::class)->add($actor, $project,
            ['staff_id' => $f['approver']->id, 'role' => 'contributor'], (string) Str::uuid7())));
        $this->assertDatabaseHas('audit_events', ['event_type' => 'projects.team_member_added', 'subject_id' => $project->id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'projects.team_member_removed', 'subject_id' => $project->id]);
    }

    public function test_milestone_states_history_assignment_and_customer_visibility_are_explicit(): void
    {
        $f = $this->projectFixture();
        $actor = $this->projectActor($f['author']);
        $project = $f['project'];
        $member = DB::transaction(fn () => app(ManageProjectTeam::class)->add($actor, $project, ['staff_id' => $f['approver']->id, 'role' => 'contributor'], (string) Str::uuid7()));
        $milestones = app(ManageMilestones::class);
        $public = DB::transaction(fn () => $milestones->create($actor, $project, $this->values(1, true, $member), (string) Str::uuid7()));
        $internal = DB::transaction(fn () => $milestones->create($actor, $project, $this->values(2, false), (string) Str::uuid7()));
        $this->rejected(fn () => DB::transaction(fn () => app(ManageProjectTeam::class)->remove($actor, $project, $member, (string) Str::uuid7())), 409);
        $this->rejected(fn () => DB::transaction(fn () => $milestones->transition($actor, $project, $public->id, 'complete', [], (string) Str::uuid7())), 409);
        foreach (['start' => 'in_progress', 'delay' => 'delayed'] as $command => $state) {
            DB::transaction(fn () => $milestones->transition($actor, $project, $public->id, $command, ['reason' => 'Dependency late'], (string) Str::uuid7()));
            self::assertSame($state, $public->refresh()->state);
        }
        DB::transaction(fn () => $milestones->transition($actor, $project, $public->id, 'start', [], (string) Str::uuid7()));
        DB::transaction(fn () => $milestones->transition($actor, $project, $public->id, 'complete', [], (string) Str::uuid7()));
        $this->rejected(fn () => DB::transaction(fn () => $milestones->update($actor, $project, $public->id, $this->values(1, true), (string) Str::uuid7())), 409);
        DB::transaction(fn () => app(ManageProjectTeam::class)->remove($actor, $project, $member, (string) Str::uuid7()));
        $visible = app(ProjectRead::class)->milestones($this->projectActor($f['customer']), $project, null, 25);
        self::assertCount(1, $visible['data']);
        self::assertSame($public->id, $visible['data'][0]->id);
        self::assertObjectNotHasProperty('responsible_member_id', $visible['data'][0]);
        $staff = app(ProjectRead::class)->milestones($actor, $project, null, 25);
        self::assertCount(2, $staff['data']);
        self::assertSame('upcoming', $internal->refresh()->state);
        self::assertSame(5, DB::table('milestone_changes')->where('milestone_id', $public->id)->count());
        $this->assertDatabaseHas('audit_events', ['event_type' => 'projects.milestone_completed', 'subject_id' => $project->id]);
    }

    public function test_foreign_project_members_and_milestones_cannot_be_used_or_manipulated(): void
    {
        $a = $this->projectFixture();
        $b = $this->projectFixture();
        $actor = $this->projectActor($a['author']);
        $member = DB::table('project_members')->where('project_id', $b['project']->id)->value('id');
        $this->rejected(fn () => DB::transaction(fn () => app(ManageMilestones::class)->create($actor, $a['project'], $this->values(1, true, $member), (string) Str::uuid7())), 404);
        $foreign = DB::transaction(fn () => app(ManageMilestones::class)->create($this->projectActor($b['author']), $b['project'], $this->values(1, true), (string) Str::uuid7()));
        $this->rejected(fn () => DB::transaction(fn () => app(ManageMilestones::class)->update($actor, $a['project'], $foreign->id, $this->values(1, true), (string) Str::uuid7())), 404);
        $this->rejected(fn () => DB::transaction(fn () => app(ManageProjectTeam::class)->remove($actor, $a['project'], $member, (string) Str::uuid7())), 404);
        self::assertSame(0, DB::table('milestones')->where('project_id', $a['project']->id)->count());
    }

    public function test_updates_are_immutable_attributable_paginated_and_owned_while_internal_activity_is_staff_only(): void
    {
        $f = $this->projectFixture();
        $actor = $this->projectActor($f['author']);
        $project = $f['project'];
        for ($i = 1; $i <= 3; $i++) {
            DB::transaction(fn () => app(PublishProjectUpdate::class)->handle($actor, $project, ['content' => 'Customer progress '.$i], (string) Str::uuid7()));
        }
        $owner = $this->projectActor($f['customer']);
        $first = app(ProjectRead::class)->updates($owner, $project, null, 2);
        self::assertCount(2, $first['data']);
        self::assertNotNull($first['meta']['next_after']);
        $next = app(ProjectRead::class)->updates($owner, $project, $first['meta']['next_after'], 2);
        self::assertCount(1, $next['data']);
        self::assertNull($next['meta']['next_after']);
        self::assertSame($actor->id, $first['data'][0]->author_id);
        self::assertObjectNotHasProperty('correlation_id', $first['data'][0]);
        self::assertNotNull($first['data'][0]->published_at);
        foreach (['activity', 'team', 'evidence'] as $method) {
            $this->rejected(fn () => app(ProjectRead::class)->$method($owner, $project, null, 25));
        }
        $foreign = $this->projectActor($this->intakeCustomer());
        $this->rejected(fn () => app(ProjectStore::class)->find($foreign, $project->id), 404);
        $this->rejected(fn () => app(ProjectRead::class)->updates($foreign, $project, null, 25));
        $this->rejected(fn () => DB::transaction(fn () => app(PublishProjectUpdate::class)->handle($owner, $project, ['content' => 'Forged'], (string) Str::uuid7())));
    }

    public function test_milestone_pages_follow_display_order_and_cannot_use_private_cursor_ids(): void
    {
        $f = $this->projectFixture();
        $actor = $this->projectActor($f['author']);
        $project = $f['project'];
        foreach ([30, 10, 20] as $order) {
            DB::transaction(fn () => app(ManageMilestones::class)->create($actor, $project, $this->values($order, true), (string) Str::uuid7()));
        }
        $private = DB::transaction(fn () => app(ManageMilestones::class)->create($actor, $project, $this->values(15, false), (string) Str::uuid7()));
        $owner = $this->projectActor($f['customer']);
        $first = app(ProjectRead::class)->milestones($owner, $project, null, 2);
        self::assertSame([10, 20], array_column($first['data'], 'display_order'));
        $second = app(ProjectRead::class)->milestones($owner, $project, $first['meta']['next_after'], 2);
        self::assertSame([30], array_column($second['data'], 'display_order'));
        self::assertNull($second['meta']['next_after']);
        $this->rejected(fn () => app(ProjectRead::class)->milestones($owner, $project, $private->id, 2), 404);
    }

    private function values(int $order, bool $visible, ?string $member = null): array
    {
        return ['name' => 'Milestone '.$order, 'description' => 'Explicit deliverable checkpoint.', 'due_date' => '2026-12-31',
            'display_order' => $order, 'customer_visible' => $visible, 'responsible_member_id' => $member];
    }

    private function rejected(callable $action, ?int $status = null): void
    {
        try {
            $action();
            self::fail('Expected project guard denial.');
        } catch (HttpException $e) {
            if ($status !== null) {
                self::assertSame($status, $e->getStatusCode());
            } else {
                self::assertContains($e->getStatusCode(), [403, 404, 409, 422]);
            }
        } catch (AuthorizationException) {
            self::assertNull($status);
        }
    }
}
