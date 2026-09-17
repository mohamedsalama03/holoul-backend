<?php

declare(strict_types=1);

namespace App\Modules\Documents\Console;

use App\Modules\Documents\Actions\ReconcileDocuments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReconcileDocumentsCommand extends Command
{
    protected $signature = 'documents:reconcile {--limit=20}';

    protected $description = 'Reconcile bounded private-document orphans without bypassing upload authorization.';

    public function handle(ReconcileDocuments $reconciler): int
    {
        try {
            $limit = $this->option('limit');
            if (! is_string($limit) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $limit) !== 1) {
                return self::FAILURE;
            }
            $result = $reconciler->handle((int) $limit);
            $this->line(json_encode(['event' => 'documents.reconciled', ...$result], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            Log::warning('documents.reconciliation_unavailable');

            return self::FAILURE;
        }
    }
}
