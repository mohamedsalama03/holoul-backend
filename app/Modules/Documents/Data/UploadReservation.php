<?php

declare(strict_types=1);

namespace App\Modules\Documents\Data;

use DateTimeImmutable;

/** Private capability used only within the server's coordinated upload action. */
final readonly class UploadReservation
{
    public function __construct(
        public string $documentId,
        public string $key,
        public int $bytes,
        public string $sha256,
        public DocumentFormat $format,
        public DateTimeImmutable $expiresAt,
    ) {}
}
