<?php

declare(strict_types=1);

namespace App\Application\Documents;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExpireIntakeUploadsCommand extends Command
{
    protected $signature = 'documents:expire-uploads {--limit=20}';

    protected $description = 'Expire bounded abandoned intake upload reservations after their safe grace period.';

    public function handle(ExpireIntakeUploads $expiry, ExpireGuestDocuments $guests): int
    {
        try {
            $limit = $this->option('limit');
            if (! is_string($limit) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $limit) !== 1) {
                return self::FAILURE;
            }
            $result = $expiry->handle((int) $limit);
            $result['guest_expired'] = $guests->handle((int) $limit);
            $this->line(json_encode(['event' => 'documents.uploads_expired', ...$result], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            Log::warning('documents.upload_expiry_unavailable');

            return self::FAILURE;
        }
    }
}
