<?php

declare(strict_types=1);

namespace App\Modules\AI\Actions;

use Illuminate\Console\Command;

final class ReconcileAIRunsCommand extends Command
{
    protected $signature = 'ai:reconcile-runs';

    protected $description = 'Finalize exhausted AI work without retrying ambiguous provider operations';

    public function handle(ReconcileAIRuns $runs): int
    {
        $runs->handle();

        return self::SUCCESS;
    }
}
