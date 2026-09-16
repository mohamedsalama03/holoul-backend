<?php

declare(strict_types=1);

namespace App\Modules\Categories\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Constructed by the authorized outer workflow, never from HTTP actor fields. */
final readonly class TaxonomyActor
{
    public function __construct(public string $id, public bool $canManage)
    {
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Taxonomy actor identifiers must be UUIDs.');
        }
    }
}
