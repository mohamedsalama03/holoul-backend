<?php

declare(strict_types=1);

namespace App\Infrastructure\Async;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReconcileOperationsCommand extends Command
{
    protected $signature = 'operations:reconcile {--limit=100 : Maximum operations per pass (1-1000)}';

    protected $description = 'Republish eligible durable operation identifiers';

    public function handle(OperationReconciler $reconciler): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

        if (! is_int($limit)) {
            $this->error('The limit must be an integer between 1 and 1000.');

            return self::INVALID;
        }

        try {
            $published = $reconciler->reconcile($limit);
        } catch (Throwable) {
            Log::warning('async.reconciliation_unavailable');
            $this->error('Operation reconciliation is temporarily unavailable.');

            return self::FAILURE;
        }

        $this->info(sprintf('Published %d operation identifiers.', $published));

        return self::SUCCESS;
    }
}
