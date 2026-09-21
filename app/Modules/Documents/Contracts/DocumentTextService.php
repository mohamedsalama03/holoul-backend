<?php

declare(strict_types=1);

namespace App\Modules\Documents\Contracts;

use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\DocumentTextSource;

/** Parent authorization belongs to the caller; storage and extraction remain private. */
interface DocumentTextService
{
    /** Requires the freshly authorized parent transaction. */
    public function capture(DocumentOwner $owner, string $documentId): DocumentTextSource;

    /** Requires the freshly authorized parent transaction. */
    public function assertCurrent(DocumentOwner $owner, DocumentTextSource $source): void;

    /** Queued work only, outside a transaction; caller reauthorizes after I/O. */
    public function extract(DocumentTextSource $source, int $maxCharacters = 20000): string;
}
