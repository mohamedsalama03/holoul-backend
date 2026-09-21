<?php

declare(strict_types=1);

namespace App\Modules\AI\Contracts;

use App\Modules\AI\Models\AIRun;

interface AICompletionNotifier
{
    /** Database-only durable notification intent in the result/cancellation transaction. */
    public function completed(AIRun $run): void;
}
