<?php

declare(strict_types=1);

use App\Application\Documents\DocumentServiceProvider;
use App\Application\Operations\ProviderTelemetryServiceProvider;
use App\Application\PublicServices\PublicServicesProvider;
use App\Infrastructure\Async\AsyncServiceProvider;
use App\Infrastructure\Operations\OperationsServiceProvider;
use App\Modules\AI\AIServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Notifications\NotificationsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ReportingServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;

return [
    AppServiceProvider::class,
    AsyncServiceProvider::class,
    SanctumServiceProvider::class,
    IdentityServiceProvider::class,
    DocumentServiceProvider::class,
    AIServiceProvider::class,
    NotificationsServiceProvider::class,
    ReportingServiceProvider::class,
    OperationsServiceProvider::class,
    ProviderTelemetryServiceProvider::class,
    PublicServicesProvider::class,
];
