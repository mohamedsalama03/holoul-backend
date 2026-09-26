<?php

declare(strict_types=1);

namespace App\Modules\Proposals\Queries;

use App\Modules\Proposals\Contracts\ProposalReportingReader;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Reviewed numeric/time-only join to Intake for the first-submission cohort. */
final class ReadProposalReporting implements ProposalReportingReader
{
    public function read(string $from, string $until): array
    {
        $cohort = DB::table('proposals')->where('issued_at', '>=', $from)->where('issued_at', '<', $until);
        $counts = (clone $cohort)->selectRaw("count(*)::int AS issued_proposal_versions,
            count(*) FILTER (WHERE state='issued' AND valid_until>clock_timestamp())::int AS pending_proposals,
            count(*) FILTER (WHERE state='accepted')::int AS accepted_proposals")->first();
        $first = DB::table('proposals as p')->join('project_requests as r', 'r.id', '=', 'p.request_id')
            ->where('r.submitted_at', '>=', $from)->where('r.submitted_at', '<', $until)->whereNotNull('p.issued_at')
            ->select('r.id', 'r.submitted_at')->selectRaw('min(p.issued_at) AS first_issued')->groupBy('r.id', 'r.submitted_at');
        $timing = DB::query()->fromSub($first, 'first_proposals')->whereColumn('first_issued', '>=', 'submitted_at')
            ->selectRaw('count(*)::int AS requests_with_issued_proposal, round(avg(extract(epoch FROM first_issued-submitted_at)),3)::text AS average_time_to_proposal_seconds')->first();
        $values = (clone $cohort)->where('state', 'accepted')->select('currency')
            ->selectRaw('count(*)::int AS proposals, sum(amount_minor)::text AS minor_units')->groupBy('currency')->orderBy('currency')->get();

        return ['counts' => $counts === null ? [] : get_object_vars($counts), 'timing' => $timing === null ? [] : get_object_vars($timing),
            'accepted_proposal_values' => $values->map(static fn (stdClass $row): array => get_object_vars($row))->all()];
    }
}
