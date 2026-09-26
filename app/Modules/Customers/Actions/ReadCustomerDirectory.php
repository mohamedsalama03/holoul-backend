<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Identity\Contracts\AuthorizedIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReadCustomerDirectory
{
    /** The outer workflow supplies owner-authorized resource queries, never HTTP SQL/filter fragments. */
    public function query(AuthorizedIdentity $actor, Builder $identities, ?Builder $requests, ?Builder $projects): Builder
    {
        if ($actor->kind !== 'staff' || ! $actor->allows('customers.directory.read') || ($requests === null && $projects === null)) {
            throw new AuthorizationException;
        }
        $query = DB::table('customers')->joinSub($identities, 'identity', 'identity.id', '=', 'customers.user_id')
            ->select('customers.id', 'identity.full_name', 'identity.email', 'identity.email_display', 'customers.phone_e164', 'customers.phone_display')
            ->selectRaw("CASE WHEN identity.enabled THEN 'active' ELSE 'disabled' END AS account_status, identity.email_verified_at IS NOT NULL AS email_verified,
                to_char(customers.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS customer_since");
        // Only both existing unrestricted business scopes justify discovering profiles without any submitted work.
        if (! ($actor->allows('intake.read') && $actor->allows('intake.read_all') && $actor->allows('projects.read') && $actor->allows('projects.read_all'))) {
            $query->where(function (Builder $visible) use ($requests, $projects): void {
                $visible->whereRaw('false');
                foreach ([$requests, $projects] as $scope) {
                    if ($scope !== null) {
                        $visible->orWhereIn('customers.id', (clone $scope)->select('customer_id'));
                    }
                }
            });
        }
        if ($requests !== null) {
            $counts = DB::query()->fromSub($requests, 'visible_requests')->select('customer_id')->selectRaw('count(*)::int AS request_count')->groupBy('customer_id');
            $query->leftJoinSub($counts, 'request_counts', 'request_counts.customer_id', '=', 'customers.id')
                ->selectRaw('coalesce(request_counts.request_count,0)::int AS request_count');
        } else {
            $query->selectRaw('0::int AS request_count');
        }
        if ($projects !== null) {
            $counts = DB::query()->fromSub($projects, 'visible_projects')->select('customer_id')
                ->selectRaw("count(*) FILTER (WHERE state IN ('planning','design','development','testing','deployment'))::int AS active_project_count,
                    count(*) FILTER (WHERE state='completed')::int AS completed_project_count,
                    count(*) FILTER (WHERE state='on_hold')::int AS on_hold_project_count")->groupBy('customer_id');
            $query->leftJoinSub($counts, 'project_counts', 'project_counts.customer_id', '=', 'customers.id')
                ->selectRaw('coalesce(project_counts.active_project_count,0)::int AS active_project_count,
                    coalesce(project_counts.completed_project_count,0)::int AS completed_project_count,
                    coalesce(project_counts.on_hold_project_count,0)::int AS on_hold_project_count');
        } else {
            $query->selectRaw('0::int AS active_project_count, 0::int AS completed_project_count, 0::int AS on_hold_project_count');
        }

        return $query;
    }
}
