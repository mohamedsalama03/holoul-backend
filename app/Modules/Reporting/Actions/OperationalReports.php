<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Customers\Contracts\CustomerReportingReader;
use App\Modules\ProjectIntake\Contracts\IntakeReportingReader;
use App\Modules\Projects\Contracts\ProjectReportingReader;
use App\Modules\Proposals\Contracts\ProposalReportingReader;
use App\Modules\Reporting\Data\ReportWindow;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Internal aggregate facade. The Application boundary supplies current reporting authority. */
final readonly class OperationalReports
{
    public function __construct(private IntakeReportingReader $requests, private ProposalReportingReader $proposals,
        private ProjectReportingReader $projects, private CustomerReportingReader $customers) {}

    /** @return array<string,mixed> */
    public function read(string $report, ReportWindow $window, ?string $categoryId = null, ?string $subcategoryId = null): array
    {
        if (! in_array($report, ['dashboard', 'requests', 'projects', 'customers'], true)
            || ($categoryId !== null && ! Str::isUuid($categoryId, 7)) || ($subcategoryId !== null && (! Str::isUuid($subcategoryId, 7) || $categoryId === null))
            || ($report !== 'requests' && ($categoryId !== null || $subcategoryId !== null))) {
            throw new HttpException(422);
        }
        $result = [];
        if (in_array($report, ['dashboard', 'requests'], true)) {
            $result['requests'] = $this->requests->read($window->from, $window->until, $categoryId, $subcategoryId);
        }
        if ($report === 'dashboard') {
            $result['proposals'] = $this->proposals->read($window->from, $window->until);
        }
        if (in_array($report, ['dashboard', 'projects'], true)) {
            $result['projects'] = $this->projects->read($window->from, $window->until);
        }
        if (in_array($report, ['dashboard', 'customers'], true)) {
            $result['customers'] = $this->customers->read($window->from, $window->until);
        }

        return ['data' => $result, 'meta' => ['window' => $window->toArray(), 'state_semantics' => 'Current states of the selected creation/submission cohort; not a historical snapshot.',
            'request_cohort' => 'First submitted within the window. Drafts excluded. Latest submitted taxonomy and estimated budget.',
            'project_cohort' => 'Created within the window. Active excludes on-hold and terminal projects.',
            'customer_cohort' => 'Customer profiles registered within the window.',
            'proposal_cohort' => 'Issued versions within the window; pending means issued and currently unexpired. Values are accepted proposals, never revenue.',
            'conversion_denominator' => 'All requests first submitted within the window; numerator currently converted; null rate for an empty cohort.',
            'review_timing' => 'First under_review to first discovery/rejected/withdrawn, including information waiting; completed reviews only.',
            'proposal_timing' => 'First request submission to first issued proposal; only issued requests in the request cohort.',
            'completion_timing' => 'Project creation to completion for completion events within the window, including hold periods.',
            'money' => 'Exact integer minor_units strings, separately grouped by USD (2 decimals) and LYD (3 decimals). No FX or revenue.',
            'trend_gaps' => 'Omitted calendar days have zero events. Category groups return the top100 and the remaining request count.']];
    }
}
