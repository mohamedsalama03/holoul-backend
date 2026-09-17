<?php

declare(strict_types=1);

namespace App\Modules\Documents\Console;

use App\Modules\Documents\Actions\RetryDocumentDeletions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RetryDocumentDeletionsCommand extends Command
{
    protected $signature = 'documents:retry-deletions {--limit=20}';

    protected $description = 'Explicitly retry a bounded page of failed deletions with an audit record.';

    public function handle(RetryDocumentDeletions $retries): int
    {
        try {
            $limit = $this->option('limit');
            if (! is_string($limit) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $limit) !== 1) {
                return self::FAILURE;
            }
            $this->line(json_encode(['event' => 'documents.deletions_retried', 'count' => $retries->handle((int) $limit)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            Log::warning('documents.deletion_retry_unavailable');

            return self::FAILURE;
        }
    }
}
