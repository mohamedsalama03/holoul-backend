<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Infrastructure\Async\OperationHandlerRegistry;
use App\Modules\Contact\Contracts\ContactMailSender;
use App\Modules\PublicPortfolio\Adapters\IsolatedImageProcessor;
use App\Modules\PublicPortfolio\Adapters\PortfolioStorageFactory;
use App\Modules\PublicPortfolio\Contracts\ImageProcessor;
use App\Modules\PublicPortfolio\Contracts\PortfolioAuthority;
use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class PublicServicesProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContactMailSender::class, ContactMailAdapter::class);
        $this->app->bind(PortfolioAuthority::class, PortfolioAuthorityAdapter::class);
        $this->app->bind(ImageProcessor::class, static fn (): ImageProcessor => new IsolatedImageProcessor(Config::string('public-content.portfolio_processor_socket')));
        $this->app->bind(PortfolioStorage::class, static fn (): PortfolioStorage => PortfolioStorageFactory::create());
    }

    public function boot(OperationHandlerRegistry $registry): void
    {
        $this->commands([ReconcileContactDeliveryCommand::class, ReconcilePortfolioCommand::class, ImportLaunchTaxonomyCommand::class, ImportLegacyPortfolioCommand::class]);
        $registry->register('contact.email', new ContactMailHandler($this->app));
        $registry->register('portfolio.process_image', new PortfolioOperationHandler($this->app, true));
        $registry->register('portfolio.invalidate', new PortfolioOperationHandler($this->app, false));
    }
}
