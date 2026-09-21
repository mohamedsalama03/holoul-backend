<?php

declare(strict_types=1);

namespace App\Modules\AI\Data;

/** An authorized immutable snapshot supplied by the owning application workflow. */
final readonly class AISource
{
    /** @param list<array{category_id:string,subcategory_id:string,category_name:string,subcategory_name:string}> $taxonomy */
    public function __construct(public string $actorId, public string $customerId, public string $parentType,
        public string $parentId, public string $sourceType, public string $sourceId, public int $sourceVersion,
        public string $sourceHash, public string $text, public ?string $documentId = null,
        public ?string $documentChecksum = null, public array $taxonomy = [], public ?string $documentObjectVersion = null) {}
}
