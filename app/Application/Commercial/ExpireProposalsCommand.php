<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use Illuminate\Console\Command;

final class ExpireProposalsCommand extends Command
{
    protected $signature = 'proposals:expire {--limit=100}';

    protected $description = 'Expire due issued proposals transactionally, in bounded batches.';

    public function handle(ExpireProposals $expiry): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || preg_match('/\A[1-9][0-9]{0,3}\z/D', $limit) !== 1 || (int) $limit > 1000) {
            return self::INVALID;
        }
        $this->line(json_encode(['event' => 'proposals.expiry_completed', 'expired' => $expiry->handle((int) $limit)], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
