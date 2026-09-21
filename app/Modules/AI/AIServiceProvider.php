<?php

declare(strict_types=1);

namespace App\Modules\AI;

use App\Infrastructure\Async\OperationHandlerRegistry;
use App\Modules\AI\Actions\ReconcileAIRunsCommand;
use App\Modules\AI\Adapters\SandboxAIProvider;
use App\Modules\AI\Contracts\AIProvider;
use Illuminate\Support\ServiceProvider;

final class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AIProvider::class, SandboxAIProvider::class);
    }

    public function boot(OperationHandlerRegistry $registry): void
    {
        $this->commands([ReconcileAIRunsCommand::class]);
        $registry->register('ai.generate', new AIOperationHandler($this->app));
    }
}
