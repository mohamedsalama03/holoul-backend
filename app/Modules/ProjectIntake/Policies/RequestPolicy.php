<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Policies;

use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Data\RequestState;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class RequestPolicy
{
    /** @param Builder<ProjectRequest> $query
     * @return Builder<ProjectRequest>
     */
    public function scope(Builder $query, IntakeActor $actor): Builder
    {
        if ($actor->customerId !== null) {
            return $query->where('customer_id', $actor->customerId);
        }
        if (! $actor->allows('intake.read')) {
            throw new AuthorizationException;
        }
        $query->where('state', '!=', RequestState::Draft->value);
        if (! $actor->allows('intake.read_all')) {
            $query->where(function (Builder $scope) use ($actor): void {
                $scope->where('assigned_staff_id', $actor->id);
                if ($actor->allows('intake.assign')) {
                    $scope->orWhereNull('assigned_staff_id');
                }
            });
        }

        return $query;
    }

    public function owner(IntakeActor $actor, ProjectRequest $record): void
    {
        if ($actor->customerId === null || $record->customer_id !== $actor->customerId) {
            throw new AuthorizationException;
        }
    }

    public function staff(IntakeActor $actor, ProjectRequest $record, string $permission, bool $assignmentRequired = true): void
    {
        if (! $actor->allows($permission)
            || ($assignmentRequired && $record->assigned_staff_id !== $actor->id && ! $actor->allows('intake.read_all'))) {
            throw new AuthorizationException;
        }
    }
}
