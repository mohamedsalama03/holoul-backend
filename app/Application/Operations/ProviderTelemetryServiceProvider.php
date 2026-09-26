<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Infrastructure\Operations\MetricRecorder;
use App\Modules\AI\Contracts\AIProvider;
use App\Modules\Notifications\Contracts\EmailProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class ProviderTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(AIProvider::class, fn (AIProvider $inner, Application $app): AIProvider => new ObservedAIProvider($inner, $app->make(MetricRecorder::class)));
        $this->app->extend(EmailProvider::class, fn (EmailProvider $inner, Application $app): EmailProvider => new ObservedEmailProvider($inner, $app->make(MetricRecorder::class)));
    }
}
