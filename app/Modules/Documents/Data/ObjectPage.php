<?php

declare(strict_types=1);

namespace App\Modules\Documents\Data;

final readonly class ObjectPage
{
    /** @param list<ObjectVersion> $objects */
    public function __construct(public array $objects, public ?string $nextCursor) {}
}
