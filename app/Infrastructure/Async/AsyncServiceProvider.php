<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Illuminate\Support\ServiceProvider;

final class AsyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // B1 intentionally installs no domain handlers.
        $this->app->singleton(OperationHandlerRegistry::class);
    }
}
