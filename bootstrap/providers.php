<?php

declare(strict_types=1);

use App\Infrastructure\Async\AsyncServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Providers\AppServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;

return [
    AppServiceProvider::class,
    AsyncServiceProvider::class,
    SanctumServiceProvider::class,
    IdentityServiceProvider::class,
];
