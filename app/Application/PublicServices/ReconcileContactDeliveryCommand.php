<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Modules\Contact\Actions\ReconcileContactDelivery;
use Illuminate\Console\Command;

final class ReconcileContactDeliveryCommand extends Command
{
    protected $signature = 'contact:reconcile-delivery';

    protected $description = 'Classify exhausted notification attempts; never deletes contact content or retries uncertain mail.';

    public function handle(ReconcileContactDelivery $action): int
    {
        $this->info('Classified: '.$action->handle(50));

        return self::SUCCESS;
    }
}
