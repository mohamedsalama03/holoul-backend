<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** All actions run under the outer application's transaction and locked Project. */
final readonly class ProjectLifecycle
{
    private const EVIDENCE = ['planning' => 'plan_approved', 'design' => 'design_approved',
        'development' => 'delivery_candidate', 'testing' => 'qa_passed', 'deployment' => 'deployment_succeeded'];

    public function __construct(private ProjectStore $store) {}

    /** @param array<string,mixed> $input */
    public function recordEvidence(ProjectActor $actor, Project $project, array $input, string $correlation): Project
    {
        $this->store->staff($actor, $project, 'projects.manage', $project->state === 'planning');
        $this->store->active($project);
        $kind = $this->text($input, 'kind', 40);
        $summary = $this->text($input, 'summary', 5000);
        if ((self::EVIDENCE[$project->state] ?? null) !== $kind) {
            throw new HttpException(409);
        }
        $this->store->changed($project);
        DB::table('project_phase_evidence')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
            'phase_epoch' => $project->phase_epoch, 'phase' => $project->state, 'kind' => $kind, 'summary' => $summary,
            'recorded_by' => $actor->id, 'entity_version' => $project->lock_version]);
        $this->store->event($project, 'phase_evidence_recorded', $actor, $correlation);

        return $project;
    }

    public function advance(ProjectActor $actor, Project $project, string $correlation): Project
    {
        $this->store->staff($actor, $project, 'projects.transition', true);
        $this->store->active($project);
        $kind = self::EVIDENCE[$project->state] ?? throw new HttpException(409);
        $evidence = DB::table('project_phase_evidence')->where('project_id', $project->id)->where('phase_epoch', $project->phase_epoch)->where('kind', $kind)->orderByDesc('entity_version')->first();
        if ($evidence === null) {
            throw new HttpException(409);
        }
        if ($project->state === 'planning' && DB::table('project_membership_history')->where('project_id', $project->id)
            ->where('entity_version', '>', $evidence->entity_version)->exists()) {
            throw new HttpException(409);
        }
        if ($project->state === 'deployment' && ! DB::table('project_completion_confirmations')->where('project_id', $project->id)
            ->where('phase_epoch', $project->phase_epoch)->where('deployment_evidence_id', $evidence->id)->exists()) {
            throw new HttpException(409);
        }
        $next = match ($project->state) {
            'planning' => 'design', 'design' => 'development', 'development' => 'testing', 'testing' => 'deployment',
            default => 'completed',
        };
        $this->move($actor, $project, $next, $next === 'completed' ? 'completed' : 'state_changed', $correlation);

        return $project;
    }

    /** @param array<string,mixed> $input */
    public function hold(ProjectActor $actor, Project $project, array $input, string $correlation): Project
    {
        $this->manager($actor, $project);
        if (! isset(self::EVIDENCE[$project->state])) {
            throw new HttpException(409);
        }
        $project->previous_phase = $project->state;
        $this->move($actor, $project, 'on_hold', 'held', $correlation, $this->text($input, 'reason', 5000), $this->text($input, 'customer_communication', 5000));

        return $project;
    }

    /** @param array<string,mixed> $input */
    public function resume(ProjectActor $actor, Project $project, array $input, string $correlation): Project
    {
        $this->manager($actor, $project);
        if ($project->state !== 'on_hold' || $project->previous_phase === null) {
            throw new HttpException(409);
        }
        $phase = $project->previous_phase;
        $project->previous_phase = null;
        $this->move($actor, $project, $phase, 'resumed', $correlation, $this->text($input, 'reason', 5000), null, $this->text($input, 'conditions', 5000));

        return $project;
    }

    /** @param array<string,mixed> $input */
    public function fail(ProjectActor $actor, Project $project, array $input, string $correlation): Project
    {
        $this->manager($actor, $project);
        $next = match ($project->state) {
            'testing' => 'development', 'deployment' => 'testing', default => throw new HttpException(409)
        };
        $this->move($actor, $project, $next, 'phase_failed', $correlation, $this->text($input, 'reason', 5000), $this->text($input, 'customer_communication', 5000));

        return $project;
    }

    /** @param array<string,mixed> $input */
    public function cancel(ProjectActor $actor, Project $project, array $input, string $correlation): Project
    {
        $this->manager($actor, $project);
        $project->previous_phase = null;
        $this->move($actor, $project, 'cancelled', 'cancelled', $correlation, $this->text($input, 'reason', 5000), $this->text($input, 'customer_communication', 5000));

        return $project;
    }

    public function confirmCompletion(ProjectActor $actor, Project $project, string $correlation): Project
    {
        $this->store->owner($actor, $project, 'projects.self.confirm', true);
        if ($project->state !== 'deployment') {
            throw new HttpException(409);
        }
        $evidence = DB::table('project_phase_evidence')->where('project_id', $project->id)->where('phase_epoch', $project->phase_epoch)
            ->where('kind', 'deployment_succeeded')->orderByDesc('entity_version')->first();
        if ($evidence === null || DB::table('project_completion_confirmations')->where('project_id', $project->id)->where('deployment_evidence_id', $evidence->id)->exists()) {
            throw new HttpException(409);
        }
        $this->store->changed($project);
        DB::table('project_completion_confirmations')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
            'phase_epoch' => $project->phase_epoch, 'deployment_evidence_id' => $evidence->id,
            'confirmed_by' => $actor->id, 'entity_version' => $project->lock_version]);
        $this->store->event($project, 'customer_completion_confirmed', $actor, $correlation);

        return $project;
    }

    private function manager(ProjectActor $actor, Project $project): void
    {
        $this->store->staff($actor, $project, 'projects.transition', true);
        $this->store->active($project);
    }

    private function move(ProjectActor $actor, Project $project, string $next, string $event, string $correlation,
        ?string $reason = null, ?string $communication = null, ?string $conditions = null): void
    {
        $from = $project->state;
        $priorEpoch = $project->phase_epoch;
        if ($communication !== null) {
            DB::table('project_updates')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
                'author_id' => $actor->id, 'content' => $communication, 'entity_version' => $project->lock_version + 1]);
        }
        $project->state = $next;
        $project->phase_epoch++;
        $this->store->changed($project);
        DB::table('project_state_changes')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
            'from_state' => $from, 'to_state' => $next, 'from_epoch' => $priorEpoch, 'to_epoch' => $project->phase_epoch,
            'actor_id' => $actor->id, 'entity_version' => $project->lock_version, 'reason' => $reason,
            'customer_communication' => $communication, 'resume_conditions' => $conditions, 'correlation_id' => $correlation]);
        $this->store->event($project, $event, $actor, $correlation, $reason);
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $field, int $max): string
    {
        Validator::make($input, [$field => ['required', 'string', 'max:'.$max]])->validate();
        $value = $input[$field];
        if (! is_string($value) || trim($value) === '') {
            throw new HttpException(422);
        }

        return $value;
    }
}
