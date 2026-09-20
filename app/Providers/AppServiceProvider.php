<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Projects\AuthorizedProjectStaff;
use App\Infrastructure\Queue\SafeFailedJobProvider;
use App\Modules\Categories\Contracts\TaxonomyReader;
use App\Modules\Categories\DatabaseTaxonomyReader;
use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Customers\ReadModels\DatabaseCustomerContactReader;
use App\Modules\Discovery\Contracts\DiscoveryReader;
use App\Modules\Discovery\DatabaseDiscoveryReader;
use App\Modules\Identity\Contracts\AuthorizedStaffReader;
use App\Modules\Identity\Contracts\ProjectStaffIdentityReader;
use App\Modules\Identity\Security\DatabaseAuthorizedStaffReader;
use App\Modules\ProjectIntake\Contracts\IntakeDocumentReader;
use App\Modules\ProjectIntake\Queries\ReadIntakeDocuments;
use App\Modules\Projects\Contracts\ProjectStaffReader;
use App\Modules\Proposals\Contracts\AcceptedProposalReader;
use App\Modules\Proposals\Queries\ReadAcceptedProposal;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProjectStaffIdentityReader::class, DatabaseAuthorizedStaffReader::class);
        $this->app->bind(ProjectStaffReader::class, AuthorizedProjectStaff::class);
        $this->app->bind(AcceptedProposalReader::class, ReadAcceptedProposal::class);
        $this->app->bind(DiscoveryReader::class, DatabaseDiscoveryReader::class);
        $this->app->bind(IntakeDocumentReader::class, ReadIntakeDocuments::class);
        $this->app->bind(TaxonomyReader::class, DatabaseTaxonomyReader::class);
        $this->app->bind(CustomerContactReader::class, DatabaseCustomerContactReader::class);
        $this->app->bind(AuthorizedStaffReader::class, DatabaseAuthorizedStaffReader::class);
        $this->app->extend('queue.failer', fn (mixed $previous, Application $app): SafeFailedJobProvider => new SafeFailedJobProvider($app->make(DatabaseManager::class), 'pgsql', 'failed_jobs'));
    }

    public function boot(): void
    {
        TrustProxies::at(array_values(array_filter(Config::array('app.trusted_proxies'), is_string(...))));
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes();
        Queue::looping(function (): void {
            file_put_contents('/tmp/holoul-queue-heartbeat', (string) time());
        });
    }
}
