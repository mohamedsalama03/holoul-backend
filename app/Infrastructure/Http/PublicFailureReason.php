<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

/** Implementations expose fixed, non-secret reason identifiers only. */
interface PublicFailureReason
{
    public function reason(): string;

    public function resourceId(): ?string;
}
