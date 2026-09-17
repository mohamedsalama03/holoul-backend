<?php

declare(strict_types=1);

namespace App\Modules\Documents\Contracts;

interface DocumentReferences
{
    /** Caller holds the document row lock; attaching also requires this lock. */
    public function hasAny(string $documentId): bool;
}
