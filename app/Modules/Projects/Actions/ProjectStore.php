<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Projects\Data\ProjectActor;
use App\Modules\Projects\Events\ProjectChanged;
use App\Modules\Projects\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ProjectStore
{
    public function __construct(private RecordAuditEvent $audit) {}

    /** @return Builder<Project> */
    public function scoped(ProjectActor $actor): Builder
    {
        $query = Project::query();
        if ($actor->customerId !== null) {
            if (! in_array('projects.self.read', $actor->permissions, true)) {
                throw new AuthorizationException;
            }

            return $query->where('customer_id', $actor->customerId)->where('customer_user_id', $actor->id);
        }
        if (! in_array('projects.read', $actor->permissions, true)) {
            throw new AuthorizationException;
        }
        if (! in_array('projects.read_all', $actor->permissions, true)) {
            $query->whereIn('id', DB::table('project_members')->select('project_id')->where('user_id', $actor->id)->where('active', true));
        }

        return $query;
    }

    public function find(ProjectActor $actor, string $id, bool $lock = true): Project
    {
        if (! Str::isUuid($id, 7)) {
            throw new HttpException(404);
        }
        $query = $this->scoped($actor)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new HttpException(404);
    }

    public function staff(ProjectActor $actor, Project $project, string $permission, bool $requireManager = false): void
    {
        if ($actor->customerId !== null || ! in_array($permission, $actor->permissions, true)) {
            throw new AuthorizationException;
        }
        $membership = DB::table('project_members')->where('project_id', $project->id)->where('user_id', $actor->id)->where('active', true);
        if ($requireManager) {
            $membership->where('role', 'project_manager');
        }
        if (! $membership->exists() && ($requireManager || ! in_array('projects.read_all', $actor->permissions, true))) {
            throw new AuthorizationException;
        }
    }

    public function owner(ProjectActor $actor, Project $project, string $permission, bool $recent = false): void
    {
        if ($actor->customerId !== $project->customer_id || $actor->id !== $project->customer_user_id
            || ! in_array($permission, $actor->permissions, true)
            || ($recent && (! $actor->verifiedEmail || ! $actor->recentAuthentication))) {
            throw new AuthorizationException;
        }
    }

    public function active(Project $project): void
    {
        if (in_array($project->state, ['completed', 'cancelled'], true)) {
            throw new HttpException(409);
        }
    }

    public function changed(Project $project): void
    {
        $project->lock_version++;
        $project->save();
    }

    public function event(Project $project, string $event, ProjectActor $actor, string $correlation, ?string $reason = null): void
    {
        DB::table('project_activity')->insert(['id' => (string) Str::uuid7(), 'project_id' => $project->id,
            'event' => $event, 'actor_id' => $actor->id, 'entity_version' => $project->lock_version,
            'reason' => $reason, 'correlation_id' => $correlation]);
        $this->audit->handle('projects.'.$event, 'projects.project', $project->id, $correlation, $actor->id);
        if (in_array($event, ['created', 'update_published', 'state_changed', 'held', 'resumed', 'phase_failed', 'cancelled', 'completed'], true)) {
            Event::dispatch(new ProjectChanged($project->id, $event, $project->lock_version, $project->customer_user_id, $correlation));
        }
    }
}
