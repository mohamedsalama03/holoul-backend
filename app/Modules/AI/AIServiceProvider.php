<?php

declare(strict_types=1);

namespace App\Modules\AI;

use App\Infrastructure\Async\OperationHandlerRegistry;
use App\Modules\AI\Actions\ReconcileAIRunsCommand;
use App\Modules\AI\Adapters\GeminiAIProvider;
use App\Modules\AI\Adapters\SandboxAIProvider;
use App\Modules\AI\Contracts\AIProvider;
use App\Modules\AI\Exceptions\AIProviderFailure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AIProvider::class, static fn (): AIProvider => match (Config::string('ai.driver')) {
            'sandbox' => new SandboxAIProvider,
            'gemini' => new GeminiAIProvider,
            default => throw new AIProviderFailure('provider_unavailable'),
        });
    }

    public function boot(OperationHandlerRegistry $registry): void
    {
        $this->commands([ReconcileAIRunsCommand::class]);
        $registry->register('ai.generate', new AIOperationHandler($this->app));
    }
}
