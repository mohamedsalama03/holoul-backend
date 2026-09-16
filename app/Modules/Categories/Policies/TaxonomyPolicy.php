<?php

declare(strict_types=1);

namespace App\Modules\Categories\Policies;

use App\Modules\Categories\Data\TaxonomyActor;

final class TaxonomyPolicy
{
    public function manage(TaxonomyActor $actor): bool
    {
        return $actor->canManage;
    }
}
