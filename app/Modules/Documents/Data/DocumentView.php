<?php

declare(strict_types=1);

namespace App\Modules\Documents\Data;

final readonly class DocumentView
{
    public function __construct(
        public string $id,
        public string $parentId,
        public string $customerId,
        public string $filename,
        public DocumentFormat $format,
        public int $bytes,
        public DocumentState $state,
        public int $version,
        public bool $retryable,
    ) {}

    /** @return array<string, bool|int|string> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'filename' => $this->filename, 'format' => $this->format->value,
            'mime' => $this->format->mime(), 'bytes' => $this->bytes, 'state' => $this->state->value,
            'version' => $this->version, 'retryable' => $this->retryable];
    }
}
