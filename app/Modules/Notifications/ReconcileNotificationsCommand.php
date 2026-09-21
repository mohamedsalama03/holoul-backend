<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Notifications\Actions\ReconcileNotifications;
use Illuminate\Console\Command;

final class ReconcileNotificationsCommand extends Command
{
    protected $signature = 'notifications:reconcile {--limit=100}';

    protected $description = 'Reconcile bounded abandoned notification deliveries without sending uncertain messages.';

    public function handle(ReconcileNotifications $reconciler): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            return self::INVALID;
        }
        $this->line((string) $reconciler->handle($limit));

        return self::SUCCESS;
    }
}
