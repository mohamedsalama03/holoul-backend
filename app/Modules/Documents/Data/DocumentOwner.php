<?php

declare(strict_types=1);

namespace App\Modules\Documents\Data;

/** Server-created context, after current identity and parent authorization. */
final readonly class DocumentOwner
{
    public function __construct(
        public string $parentId,
        public string $customerId,
        public string $userId,
        public string $actorId,
        public string $requestId,
    ) {}
}
