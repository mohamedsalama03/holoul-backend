<?php

declare(strict_types=1);

namespace App\Modules\Documents\Contracts;

use App\Modules\Documents\Data\DocumentDownload;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\DocumentView;
use App\Modules\Documents\Data\StoredObject;
use App\Modules\Documents\Data\UploadReservation;

/** Metadata-only actions: caller holds freshly authorized parent/identity locks. */
interface DocumentService
{
    public function reserve(DocumentOwner $owner, string $filename, int $bytes, string $sha256, string $idempotencyKey): UploadReservation;

    public function uploadReservation(DocumentOwner $owner, string $documentId): UploadReservation;

    public function finalize(DocumentOwner $owner, string $documentId, StoredObject $object): DocumentView;

    public function metadata(DocumentOwner $owner, string $documentId, bool $lock = false): DocumentView;

    public function lockAttachable(DocumentOwner $owner, string $documentId): DocumentView;

    public function requestDeletion(DocumentOwner $owner, string $documentId): DocumentView;

    public function retryScan(DocumentOwner $owner, string $documentId): DocumentView;

    public function authorizeDownload(DocumentOwner $owner, string $documentId): DocumentDownload;

    public function consumeDownload(DocumentOwner $owner, DocumentDownload $grant): void;
}
