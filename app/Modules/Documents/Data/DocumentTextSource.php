<?php

declare(strict_types=1);

namespace App\Modules\Documents\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Private immutable source identity. Never expose this snapshot through HTTP. */
final readonly class DocumentTextSource
{
    public function __construct(public string $documentId, public string $parentId, public string $customerId,
        public string $customerUserId, public int $documentVersion, public string $storageVersion,
        public string $sha256, public DocumentFormat $format, public int $bytes)
    {
        foreach ([$documentId, $parentId, $customerId, $customerUserId] as $id) {
            if (! Str::isUuid($id, 7)) {
                throw new InvalidArgumentException('Invalid document source identity.');
            }
        }
        if ($documentVersion < 1 || $storageVersion === '' || strlen($storageVersion) > 1024
            || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1 || $bytes < 1 || $bytes > 10485760) {
            throw new InvalidArgumentException('Invalid document source version.');
        }
    }

    /** @return array<string,int|string> */
    public function toArray(): array
    {
        return ['document_id' => $this->documentId, 'parent_id' => $this->parentId, 'customer_id' => $this->customerId,
            'customer_user_id' => $this->customerUserId, 'document_version' => $this->documentVersion,
            'storage_version' => $this->storageVersion, 'sha256' => $this->sha256, 'format' => $this->format->value, 'bytes' => $this->bytes];
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        foreach (['document_id', 'parent_id', 'customer_id', 'customer_user_id', 'storage_version', 'sha256', 'format'] as $key) {
            if (! is_string($input[$key] ?? null)) {
                throw new InvalidArgumentException('Invalid document source snapshot.');
            }
        }
        if (! is_int($input['document_version'] ?? null) || ! is_int($input['bytes'] ?? null) || count($input) !== 9) {
            throw new InvalidArgumentException('Invalid document source snapshot.');
        }
        $format = DocumentFormat::tryFrom($input['format']);
        if ($format === null) {
            throw new InvalidArgumentException('Invalid document source format.');
        }

        return new self($input['document_id'], $input['parent_id'], $input['customer_id'], $input['customer_user_id'],
            $input['document_version'], $input['storage_version'], $input['sha256'], $format, $input['bytes']);
    }
}
