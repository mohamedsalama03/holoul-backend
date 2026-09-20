<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Application\Projects\ConvertRequest;
use App\Application\Projects\ProjectWorkflow;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\Support\CommercialDatabase;
use Tests\Support\CommercialFixtures;
use Tests\TestCase;

final class ProjectConcurrencyTest extends TestCase
{
    use CommercialDatabase;
    use CommercialFixtures;

    #[DataProvider('conversionCompetitors')]
    public function test_conversion_serializes_with_duplicate_conversion_owner_rescission_and_request_withdrawal(string $competitor): void
    {
        $fixture = $this->acceptedFixture();
        $base = ['request' => $fixture['request']->id, 'proposal' => $fixture['proposal']->id,
            'etag' => $this->commercialEtag($fixture['request']->refresh())];
        $results = $this->race([
            [...$base, 'actor' => $fixture['author']->id, 'action' => 'project.convert', 'key' => (string) Str::uuid7()],
            [...$base, 'actor' => $competitor === 'project.convert' ? $fixture['author']->id : $fixture['customer']->id,
                'action' => $competitor, 'key' => (string) Str::uuid7()],
        ], 'project_requests', 'request');
        $this->assertOneStaleLoser($results);
        $request = $fixture['request']->refresh();
        $proposal = $fixture['proposal']->refresh();
        $converted = $request->state === RequestState::Converted;
        self::assertSame($converted ? 1 : 0, DB::table('projects')->where('source_request_id', $request->id)->count());
        self::assertSame($converted ? 'accepted' : 'rescinded', $proposal->state);
        self::assertSame($converted ? RequestState::Converted : ($competitor === 'request.withdraw' ? RequestState::Withdrawn : RequestState::Discovery), $request->state);
        self::assertSame(1, DB::table('proposal_decisions')->where('proposal_id', $proposal->id)->where('decision', 'accepted')->count());
        self::assertSame($converted ? 0 : 1, DB::table('proposal_decisions')->where('proposal_id', $proposal->id)->where('decision', 'rescinded')->count());
        self::assertSame($converted ? 1 : 0, DB::table('request_state_changes')->where('request_id', $request->id)->where('to_state', 'converted')->count());
        self::assertSame($converted ? 1 : 0, DB::table('project_command_keys')->where('operation', 'project.convert')->count());
        self::assertSame($converted ? 1 : 0, DB::table('audit_events')->where('event_type', 'projects.created')->count());
    }

    public static function conversionCompetitors(): array
    {
        return [['project.convert'], ['proposal.rescind'], ['request.withdraw']];
    }

    public function test_same_conversion_key_replays_exactly_one_project_and_baseline(): void
    {
        $fixture = $this->acceptedFixture();
        $operation = ['request' => $fixture['request']->id, 'actor' => $fixture['author']->id, 'action' => 'project.convert',
            'etag' => $this->commercialEtag($fixture['request']->refresh()), 'key' => (string) Str::uuid7()];
        $results = $this->race([$operation, $operation], 'project_requests', 'request');
        self::assertSame([200, 200], array_column($results, 'status'));
        self::assertSame($results[0]['data'], $results[1]['data']);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_command_keys', 1);
        $this->assertDatabaseHas('projects', ['source_request_id' => $fixture['request']->id, 'accepted_proposal_id' => $fixture['proposal']->id]);
        self::assertSame(1, DB::table('project_state_changes')->whereNull('from_state')->count());
    }

    #[DataProvider('transitionCompetitors')]
    public function test_simultaneous_phase_commands_commit_one_valid_transition(string $competitor): void
    {
        $fixture = $this->convertedFixture();
        $this->projectCommand($fixture, 'project.evidence', ['kind' => 'plan_approved', 'summary' => 'Approved plan.']);
        $project = $fixture['project']->refresh();
        $before = $project->lock_version;
        $base = ['project' => $project->id, 'actor' => $fixture['author']->id,
            'etag' => VersionPrecondition::etag($project->id, $before)];
        $results = $this->race([
            [...$base, 'action' => 'project.advance', 'key' => (string) Str::uuid7()],
            [...$base, 'action' => $competitor, 'key' => (string) Str::uuid7(),
                'input' => $competitor === 'project.hold' ? ['reason' => 'Concurrent hold.', 'customer_communication' => 'Customer informed.'] : []],
        ]);
        $this->assertOneStaleLoser($results);
        $project->refresh();
        self::assertContains($project->state, $competitor === 'project.hold' ? ['design', 'on_hold'] : ['design']);
        self::assertSame($before + 1, $project->lock_version);
        self::assertSame(2, $project->phase_epoch);
        self::assertSame($project->state === 'on_hold' ? 'planning' : null, $project->previous_phase);
        self::assertSame(1, DB::table('project_state_changes')->where('project_id', $project->id)->whereNotNull('from_state')->count());
        self::assertSame(1, DB::table('project_activity')->where('project_id', $project->id)->whereIn('event', ['state_changed', 'held'])->count());
    }

    public static function transitionCompetitors(): array
    {
        return [['project.advance'], ['project.hold']];
    }

    public function test_concurrent_resumptions_restore_the_saved_phase_once_and_invalidate_prior_evidence(): void
    {
        $fixture = $this->convertedFixture();
        $this->projectCommand($fixture, 'project.evidence', ['kind' => 'plan_approved', 'summary' => 'Approved pre-hold plan.']);
        $this->projectCommand($fixture, 'project.hold', ['reason' => 'Blocked.', 'customer_communication' => 'Customer informed.']);
        $project = $fixture['project']->refresh();
        $operation = ['project' => $project->id, 'actor' => $fixture['author']->id, 'action' => 'project.resume',
            'etag' => VersionPrecondition::etag($project->id, $project->lock_version),
            'input' => ['reason' => 'Ready.', 'conditions' => 'Blocker resolved.']];
        $results = $this->race([
            [...$operation, 'key' => (string) Str::uuid7()], [...$operation, 'key' => (string) Str::uuid7()],
        ]);
        $this->assertOneStaleLoser($results);
        self::assertSame('planning', $project->refresh()->state);
        self::assertNull($project->previous_phase);
        self::assertSame(3, $project->phase_epoch);
        self::assertSame(1, DB::table('project_activity')->where('project_id', $project->id)->where('event', 'resumed')->count());
        try {
            $this->projectCommand($fixture, 'project.advance');
            self::fail('Pre-hold evidence was reused after resume.');
        } catch (HttpExceptionInterface $error) {
            self::assertSame(409, $error->getStatusCode());
        }
    }

    public function test_stale_lifecycle_command_cannot_change_newer_phase_or_history(): void
    {
        $fixture = $this->convertedFixture();
        $project = $fixture['project'];
        $stale = VersionPrecondition::etag($project->id, $project->lock_version);
        $this->projectCommand($fixture, 'project.evidence', ['kind' => 'plan_approved', 'summary' => 'Current plan.']);
        $this->projectCommand($fixture, 'project.advance');
        $before = DB::table('project_state_changes')->where('project_id', $project->id)->count();
        try {
            $this->projectCommand($fixture, 'project.hold', ['reason' => 'Stale.', 'customer_communication' => 'Stale communication.'], etag: $stale);
            self::fail('Stale command changed lifecycle.');
        } catch (HttpExceptionInterface $error) {
            self::assertSame(412, $error->getStatusCode());
        }
        self::assertSame('design', $project->refresh()->state);
        self::assertSame($before, DB::table('project_state_changes')->where('project_id', $project->id)->count());
        self::assertSame(0, DB::table('project_activity')->where('project_id', $project->id)->where('event', 'held')->count());
    }

    public function test_concurrent_milestone_updates_have_one_versioned_winner_without_lost_history(): void
    {
        $fixture = $this->convertedFixture();
        $values = ['name' => 'Baseline milestone', 'description' => '', 'display_order' => 1, 'customer_visible' => true];
        $this->projectCommand($fixture, 'project.milestone.create', $values);
        $project = $fixture['project']->refresh();
        $id = DB::table('milestones')->where('project_id', $project->id)->value('id');
        $base = ['project' => $project->id, 'id' => $id, 'actor' => $fixture['author']->id, 'action' => 'project.milestone.update',
            'etag' => VersionPrecondition::etag($project->id, $project->lock_version)];
        $results = $this->race([
            [...$base, 'input' => [...$values, 'name' => 'Winner A'], 'key' => (string) Str::uuid7()],
            [...$base, 'input' => [...$values, 'name' => 'Winner B'], 'key' => (string) Str::uuid7()],
        ]);
        $this->assertOneStaleLoser($results);
        $milestone = DB::table('milestones')->where('id', $id)->first();
        self::assertContains($milestone->name, ['Winner A', 'Winner B']);
        self::assertSame(2, $milestone->lock_version);
        self::assertSame(2, DB::table('milestone_changes')->where('milestone_id', $id)->count());
        self::assertSame(1, DB::table('milestone_changes')->where('milestone_id', $id)->where('event', 'updated')->count());
        self::assertSame(1, DB::table('project_activity')->where('project_id', $project->id)->where('event', 'milestone_updated')->count());
    }

    #[DataProvider('membershipMutations')]
    public function test_concurrent_membership_mutations_preserve_single_active_membership_and_history(bool $remove): void
    {
        $fixture = $this->convertedFixture();
        $staff = $this->intakeStaff('business_analyst');
        $values = ['staff_id' => $staff->id, 'role' => 'business_analyst'];
        if ($remove) {
            $this->projectCommand($fixture, 'project.team.add', $values);
        }
        $project = $fixture['project']->refresh();
        $member = DB::table('project_members')->where('project_id', $project->id)->where('user_id', $staff->id)->value('id');
        $operation = ['project' => $project->id, 'id' => $member ?? '', 'actor' => $fixture['author']->id,
            'action' => $remove ? 'project.team.remove' : 'project.team.add',
            'etag' => VersionPrecondition::etag($project->id, $project->lock_version), 'input' => $remove ? [] : $values];
        $results = $this->race([
            [...$operation, 'key' => (string) Str::uuid7()], [...$operation, 'key' => (string) Str::uuid7()],
        ]);
        $this->assertOneStaleLoser($results);
        self::assertSame(1, DB::table('project_members')->where('project_id', $project->id)->where('user_id', $staff->id)->count());
        self::assertSame($remove ? 0 : 1, DB::table('project_members')->where('project_id', $project->id)->where('user_id', $staff->id)->where('active', true)->count());
        self::assertSame($remove ? 1 : 2, DB::table('project_members')->where('project_id', $project->id)->where('active', true)->count());
        self::assertSame($remove ? 3 : 2, DB::table('project_membership_history')->where('project_id', $project->id)->count());
    }

    public static function membershipMutations(): array
    {
        return [[false], [true]];
    }

    public function test_parallel_conversion_on_distinct_requests_allocates_distinct_never_reused_references(): void
    {
        $operations = [];
        foreach ([1, 2] as $unused) {
            $fixture = $this->acceptedFixture();
            $operations[] = ['request' => $fixture['request']->id, 'actor' => $fixture['author']->id, 'action' => 'project.convert',
                'etag' => $this->commercialEtag($fixture['request']->refresh()), 'key' => (string) Str::uuid7()];
        }
        $results = $this->race($operations, 'project_requests', 'request');
        self::assertSame([200, 200], array_column($results, 'status'));
        $references = DB::table('projects')->whereIn('source_request_id', array_column($operations, 'request'))->pluck('reference')->all();
        self::assertCount(2, array_unique($references));
        foreach ($references as $reference) {
            self::assertMatchesRegularExpression('/^PRJ-[0-9]{4}-[0-9]{5,19}$/', $reference);
        }
        DB::beginTransaction();
        $reserved = DB::scalar("SELECT nextval('project_reference_sequence')");
        DB::rollBack();
        self::assertGreaterThan($reserved, DB::scalar("SELECT nextval('project_reference_sequence')"));
    }

    private function acceptedFixture(): array
    {
        $fixture = $this->commercialFixture();
        $proposal = $this->issueProposal($fixture);
        $this->commercialCommand($fixture['customer'], $fixture['request'], 'proposal.accept', $proposal->id);

        return [...$fixture, 'proposal' => $proposal->refresh()];
    }

    private function convertedFixture(): array
    {
        $fixture = $this->acceptedFixture();
        $result = app(ConvertRequest::class)->handle($this->projectActor($fixture['author']), $fixture['request']->id,
            $this->commercialEtag($fixture['request']->refresh()), (string) Str::uuid7(), (string) Str::uuid7());

        return [...$fixture, 'project' => Project::query()->findOrFail($result['id'])];
    }

    private function projectActor(User $user): ProjectActor
    {
        $actor = $this->intakeActor($user);

        return new ProjectActor($actor->id, $actor->customerId, $actor->verifiedEmail, true, $actor->permissions);
    }

    private function projectCommand(array $fixture, string $operation, array $input = [], string $id = '', ?string $etag = null): array
    {
        $project = $fixture['project']->refresh();

        return app(ProjectWorkflow::class)->handle($this->projectActor($fixture['author']), $project->id, $id, $operation,
            $etag ?? VersionPrecondition::etag($project->id, $project->lock_version), (string) Str::uuid7(), $input, (string) Str::uuid7());
    }

    private function assertOneStaleLoser(array $results): void
    {
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame([200, 412], $statuses);
    }

    private function race(array $operations, string $table = 'projects', string $parentField = 'project'): array
    {
        $application = 'project-race-'.Str::uuid7();
        $peers = [];
        DB::beginTransaction();
        try {
            DB::table($table)->whereIn('id', array_column($operations, $parentField))->orderBy('id')->lockForUpdate()->get();
            $parent = DB::scalar('SELECT pg_backend_pid()');
            foreach ($operations as $index => $operation) {
                $payload = base64_encode(json_encode([...$operation, 'application' => $application.'-'.$index], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/project-concurrency-worker.php'), $payload], base_path(), timeout: 20);
                $process->start();
                $peers[] = $process;
            }
            $deadline = microtime(true) + 8;
            $waiting = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::table('pg_stat_activity')->where('application_name', 'like', $application.'%')->where('wait_event_type', 'Lock')->count();
                if ($waiting === 2) {
                    break;
                }
                foreach ($peers as $peer) {
                    if (! $peer->isRunning()) {
                        self::fail($peer->getErrorOutput().$peer->getOutput());
                    }
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both independent PostgreSQL workers must wait on the parent lock.');
            DB::commit();
            $results = [];
            foreach ($peers as $peer) {
                self::assertSame(0, $peer->wait(), $peer->getErrorOutput().$peer->getOutput());
                $result = json_decode($peer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                self::assertNotSame($parent, $result['backend']);
                $results[] = $result;
            }
            self::assertNotSame($results[0]['backend'], $results[1]['backend']);

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($peers as $peer) {
                if ($peer->isRunning()) {
                    $peer->stop(0);
                }
            }
        }
    }
}
