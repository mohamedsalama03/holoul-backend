<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Contracts\DocumentTextExtractor;
use App\Modules\Documents\Contracts\DocumentTextService;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\Documents\Data\DocumentState;
use App\Modules\Documents\Data\DocumentTextSource;
use App\Modules\Documents\Exceptions\DocumentExtractionRejected;
use App\Modules\Documents\Exceptions\DocumentSourceUnavailable;
use App\Modules\Documents\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final readonly class ExtractDocumentText implements DocumentTextService
{
    public function __construct(private PrivateObjectStore $objects, private DocumentTextExtractor $extractor) {}

    public function capture(DocumentOwner $owner, string $documentId): DocumentTextSource
    {
        ManageDocuments::requireTransaction();
        if (! Str::isUuid($documentId, 7)) {
            throw new DocumentSourceUnavailable;
        }
        $document = Document::query()->whereKey($documentId)->where('parent_id', $owner->parentId)
            ->where('customer_id', $owner->customerId)->where('customer_user_id', $owner->userId)
            ->where('state', DocumentState::Available->value)->sharedLock()->first();
        if ($document === null || $document->storage_version === null) {
            throw new DocumentSourceUnavailable;
        }

        return new DocumentTextSource($document->id, $document->parent_id, $document->customer_id, $document->customer_user_id,
            $document->lock_version, $document->storage_version, $document->expected_sha256, $document->format, $document->expected_size);
    }

    public function assertCurrent(DocumentOwner $owner, DocumentTextSource $source): void
    {
        if ($this->capture($owner, $source->documentId) != $source) {
            throw new DocumentSourceUnavailable;
        }
    }

    public function extract(DocumentTextSource $source, int $maxCharacters = 20000): string
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Document text extraction must run outside a database transaction.');
        }
        if ($maxCharacters < 1 || $maxCharacters > 20000) {
            throw new InvalidArgumentException('Document text extraction accepts 1 to 20000 characters.');
        }
        $document = DB::transaction(fn (): Document => $this->current($source));
        $stream = $this->objects->openVerified($document->object());
        try {
            $text = $this->extractor->extract($stream, $source->bytes, $source->format, $maxCharacters);
        } finally {
            fclose($stream);
        }
        DB::transaction(fn (): Document => $this->current($source));
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            throw new DocumentExtractionRejected('invalid_structure');
        }
        if (mb_strlen($text, 'UTF-8') > $maxCharacters) {
            throw new DocumentExtractionRejected('resource_limit');
        }
        if (trim($text) === '') {
            throw new DocumentExtractionRejected('no_text');
        }

        return $text;
    }

    private function current(DocumentTextSource $source): Document
    {
        $document = Document::query()->whereKey($source->documentId)->where('parent_id', $source->parentId)
            ->where('customer_id', $source->customerId)->where('customer_user_id', $source->customerUserId)
            ->where('state', DocumentState::Available->value)->where('lock_version', $source->documentVersion)
            ->where('storage_version', $source->storageVersion)->where('expected_sha256', $source->sha256)
            ->where('format', $source->format->value)->where('expected_size', $source->bytes)->sharedLock()->first();

        return $document ?? throw new DocumentSourceUnavailable;
    }
}
