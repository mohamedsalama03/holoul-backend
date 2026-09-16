<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Queue\SafeFailedJobProvider;
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
