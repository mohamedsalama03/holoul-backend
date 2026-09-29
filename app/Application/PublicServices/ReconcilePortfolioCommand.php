<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Modules\PublicPortfolio\Actions\ReconcilePortfolio;
use Illuminate\Console\Command;

final class ReconcilePortfolioCommand extends Command
{
    protected $signature = 'portfolio:reconcile {--limit=20}';

    protected $description = 'Expire private image reservations and purge retired bytes after the safety grace period.';

    public function handle(ReconcilePortfolio $action): int
    {
        $raw = $this->option('limit');
        if (! is_string($raw) || ! ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 100) {
            return self::INVALID;
        }
        $this->line(json_encode($action->handle((int) $raw), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
