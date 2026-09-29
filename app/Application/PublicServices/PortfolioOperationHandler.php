<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Infrastructure\Async\OperationClaim;
use App\Infrastructure\Async\OperationHandler;
use App\Modules\PublicPortfolio\Actions\ApplyOriginInvalidation;
use App\Modules\PublicPortfolio\Actions\ProcessPortfolioImage;
use Closure;
use Illuminate\Contracts\Container\Container;

final readonly class PortfolioOperationHandler implements OperationHandler
{
    public function __construct(private Container $container, private bool $image) {}

    public function execute(OperationClaim $operation): Closure
    {
        return $this->image ? $this->container->make(ProcessPortfolioImage::class)->execute($operation)
            : $this->container->make(ApplyOriginInvalidation::class)->execute($operation);
    }
}
