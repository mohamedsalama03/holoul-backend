<?php

declare(strict_types=1);

namespace App\Modules\Categories\Data;

final readonly class TaxonomyPage
{
    /** @param list<TaxonomyRecord> $items */
    public function __construct(public array $items, public ?string $nextCursor) {}
}
