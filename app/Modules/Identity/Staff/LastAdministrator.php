<?php

declare(strict_types=1);

namespace App\Modules\Identity\Staff;

use App\Infrastructure\Http\PublicFailureReason;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class LastAdministrator extends ConflictHttpException implements PublicFailureReason
{
    public function resourceId(): ?string
    {
        return null;
    }

    public function reason(): string
    {
        return 'LAST_ENABLED_SUPER_ADMIN';
    }
}
