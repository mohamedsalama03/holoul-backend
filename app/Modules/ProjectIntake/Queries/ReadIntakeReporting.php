<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Queries;

use App\Modules\ProjectIntake\Contracts\IntakeReportingReader;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ReadIntakeReporting implements IntakeReportingReader
{
    public function read(string $from, string $until, ?string $categoryId = null, ?string $subcategoryId = null): array
    {
        $cohort = DB::table('project_requests as r')->join('request_revisions as v', 'v.id', '=', 'r.latest_revision_id')
            ->where('r.submitted_at', '>=', $from)->where('r.submitted_at', '<', $until);
        if ($categoryId !== null) {
            $cohort->where('v.category_id', $categoryId);
        }
        if ($subcategoryId !== null) {
            $cohort->where('v.subcategory_id', $subcategoryId);
        }
        $counts = (clone $cohort)->selectRaw("count(*)::int AS total_requests,
            count(*) FILTER (WHERE r.state='submitted')::int AS new_requests,
            count(*) FILTER (WHERE r.state='under_review')::int AS under_review,
            count(*) FILTER (WHERE r.state='information_required')::int AS information_required,
            count(*) FILTER (WHERE r.state='converted')::int AS converted_requests,
            count(*) FILTER (WHERE v.budget_unknown)::int AS unknown_budget_requests,
            round(100.0*count(*) FILTER (WHERE r.state='converted')/NULLIF(count(*),0),2)::text AS conversion_rate_percent")->first();
        $reviewHistory = DB::table('request_state_changes')->select('request_id')
            ->selectRaw("min(created_at) FILTER (WHERE to_state='under_review') AS review_started,
                min(created_at) FILTER (WHERE to_state IN ('discovery','rejected','withdrawn')) AS review_ended")
            ->whereIn('request_id', (clone $cohort)->select('r.id'))->groupBy('request_id');
        $review = DB::query()->fromSub($reviewHistory, 'review')->whereNotNull('review_started')->whereColumn('review_ended', '>=', 'review_started')
            ->selectRaw('count(*)::int AS reviewed_requests, round(avg(extract(epoch FROM review_ended-review_started)),3)::text AS average_review_seconds')->first();
        $groups = (clone $cohort)->select('v.category_id', 'v.subcategory_id')->selectRaw('count(*)::int AS requests')
            ->groupBy('v.category_id', 'v.subcategory_id')->orderByDesc('requests')->orderBy('v.category_id')->orderBy('v.subcategory_id')->limit(100)->get();
        $shown = $groups->sum(static fn (stdClass $row): int => is_int($row->requests) ? $row->requests : 0);
        $total = $counts !== null && is_int($counts->total_requests) ? $counts->total_requests : 0;

        return ['counts' => $counts === null ? [] : get_object_vars($counts), 'review' => $review === null ? [] : get_object_vars($review),
            'status_distribution' => $this->rows((clone $cohort)->select('r.state')->selectRaw('count(*)::int AS requests')->groupBy('r.state')->orderBy('r.state')),
            'trends' => $this->rows((clone $cohort)->selectRaw("to_char(r.submitted_at AT TIME ZONE 'UTC','YYYY-MM-DD') AS day,count(*)::int AS requests")
                ->groupByRaw("to_char(r.submitted_at AT TIME ZONE 'UTC','YYYY-MM-DD')")->orderBy('day')->limit(366)),
            'by_category' => ['rows' => $groups->map(static fn (stdClass $row): array => get_object_vars($row))->all(),
                'other_requests' => $total - $shown, 'limit' => 100],
            'estimated_budgets' => $this->rows((clone $cohort)->where('v.budget_unknown', false)->select('v.currency')
                ->selectRaw('count(*)::int AS requests, sum(v.budget_minor)::text AS minor_units')->groupBy('v.currency')->orderBy('v.currency'))];
    }

    /** @return array<int,array<array-key,mixed>> */
    private function rows(Builder $query): array
    {
        return $query->get()->map(static fn (stdClass $row): array => get_object_vars($row))->all();
    }
}
