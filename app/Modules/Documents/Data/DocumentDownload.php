<?php

declare(strict_types=1);

namespace App\Modules\Documents\Data;

use DateTimeImmutable;

/** Internal exact-version download descriptor; the API exposes no storage identity. */
final readonly class DocumentDownload
{
    public function __construct(public DocumentView $document, public StoredObject $object,
        public string $actorId, public DateTimeImmutable $expiresAt) {}
}
