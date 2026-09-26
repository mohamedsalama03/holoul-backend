<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

final class OperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MetricRecorder::class);
        $this->app->singleton(RequestMeasurements::class);
    }

    public function boot(): void
    {
        $this->commands([ObserveOperationsCommand::class, ValidateConfigurationCommand::class, ProcessHeartbeatCommand::class]);
        DB::listen(function (QueryExecuted $query): void {
            $this->app->make(RequestMeasurements::class)->query($query->time);
            if ($query->time >= Config::integer('operations.slow_query_ms')) {
                $this->app->make(MetricRecorder::class)->slowQuery();
            }
        });
        Queue::looping(fn () => $this->app->make(ProcessTelemetry::class)->heartbeat());
        Queue::before(fn (JobProcessing $event) => $this->app->make(ProcessTelemetry::class)->heartbeat($event->connectionName === 'redis' ? 'default' : $event->connectionName, 'started'));
        Queue::after(fn (JobProcessed $event) => $this->app->make(ProcessTelemetry::class)->heartbeat($event->connectionName === 'redis' ? 'default' : $event->connectionName, 'finished'));
        Queue::exceptionOccurred(fn (JobExceptionOccurred $event) => $this->app->make(ProcessTelemetry::class)->heartbeat($event->connectionName === 'redis' ? 'default' : $event->connectionName, 'failed'));
    }
}
