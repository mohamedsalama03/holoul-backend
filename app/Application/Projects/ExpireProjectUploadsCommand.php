<?php

declare(strict_types=1);

namespace App\Application\Projects;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExpireProjectUploadsCommand extends Command
{
    protected $signature = 'documents:expire-project-uploads {--limit=20}';

    protected $description = 'Expire bounded abandoned project upload reservations after the B4 grace period.';

    public function handle(ExpireProjectUploads $expiry): int
    {
        try {
            $limit = $this->option('limit');
            if (! is_string($limit) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $limit) !== 1) {
                return self::FAILURE;
            }
            $result = $expiry->handle((int) $limit);
            $this->line(json_encode(['event' => 'projects.document_uploads_expired', ...$result], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            Log::warning('projects.document_upload_expiry_unavailable');

            return self::FAILURE;
        }
    }
}
