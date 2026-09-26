<?php

declare(strict_types=1);

namespace App\Modules\Projects\Queries;

use App\Modules\Projects\Contracts\ProjectReportingReader;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ReadProjectReporting implements ProjectReportingReader
{
    public function read(string $from, string $until): array
    {
        $cohort = DB::table('projects')->where('created_at', '>=', $from)->where('created_at', '<', $until);
        $counts = (clone $cohort)->selectRaw("count(*)::int AS total_projects,
            count(*) FILTER (WHERE state IN ('planning','design','development','testing','deployment'))::int AS active_projects,
            count(*) FILTER (WHERE state='on_hold')::int AS on_hold_projects,
            count(*) FILTER (WHERE state='completed')::int AS completed_projects,
            count(*) FILTER (WHERE state='cancelled')::int AS cancelled_projects,
            round(100.0*count(*) FILTER (WHERE state='completed')/NULLIF(count(*),0),2)::text AS completion_rate_percent")->first();
        $events = DB::table('project_state_changes as h')->join('projects as p', 'p.id', '=', 'h.project_id')->where('h.to_state', 'completed')
            ->where('h.created_at', '>=', $from)->where('h.created_at', '<', $until);
        $completion = (clone $events)->selectRaw('count(*)::int AS completed_in_period, round(avg(extract(epoch FROM h.created_at-p.created_at)),3)::text AS average_completion_seconds')->first();
        $pipeline = (clone $cohort)->select('state')->selectRaw('count(*)::int AS projects')->groupBy('state')->orderBy('state')->get();
        $trend = (clone $events)->selectRaw("to_char(h.created_at AT TIME ZONE 'UTC','YYYY-MM-DD') AS day,count(*)::int AS projects")
            ->groupByRaw("to_char(h.created_at AT TIME ZONE 'UTC','YYYY-MM-DD')")->orderBy('day')->limit(366)->get();

        return ['counts' => $counts === null ? [] : get_object_vars($counts), 'completion' => $completion === null ? [] : get_object_vars($completion),
            'pipeline' => $pipeline->map(static fn (stdClass $row): array => get_object_vars($row))->all(),
            'completion_trend' => $trend->map(static fn (stdClass $row): array => get_object_vars($row))->all()];
    }
}
