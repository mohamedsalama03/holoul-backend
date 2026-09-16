<?php

declare(strict_types=1);

use App\Infrastructure\Async\AsyncServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AsyncServiceProvider::class,
];
