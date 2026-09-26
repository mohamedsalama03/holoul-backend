<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Customers\Contracts\CustomerReportingReader;
use App\Modules\Customers\ReadModels\ReadCustomerReporting;
use App\Modules\ProjectIntake\Contracts\IntakeReportingReader;
use App\Modules\ProjectIntake\Queries\ReadIntakeReporting;
use App\Modules\Projects\Contracts\ProjectReportingReader;
use App\Modules\Projects\Queries\ReadProjectReporting;
use App\Modules\Proposals\Contracts\ProposalReportingReader;
use App\Modules\Proposals\Queries\ReadProposalReporting;
use Illuminate\Support\ServiceProvider;

final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IntakeReportingReader::class, ReadIntakeReporting::class);
        $this->app->bind(ProposalReportingReader::class, ReadProposalReporting::class);
        $this->app->bind(ProjectReportingReader::class, ReadProjectReporting::class);
        $this->app->bind(CustomerReportingReader::class, ReadCustomerReporting::class);
    }
}
