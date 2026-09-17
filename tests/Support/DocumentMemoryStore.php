<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\ObjectPage;
use App\Modules\Documents\Data\ObjectVersion;
use App\Modules\Documents\Data\StoredObject;
use App\Modules\Documents\Exceptions\StorageConflict;
use App\Modules\Documents\Exceptions\StorageUnavailable;
use Closure;
use DateTimeImmutable;

/** Fault injection for orchestration tests; real S3 is tested separately. */
final class DocumentMemoryStore implements PrivateObjectStore
{
    /** @var array<string,array{object:StoredObject,bytes:string,created:DateTimeImmutable}> */
    public array $objects = [];

    public ?Closure $afterPut = null;

    public ?Closure $afterOpen = null;

    public bool $failPut = false;

    public bool $failDelete = false;

    public function putIfAbsent(string $key, mixed $stream, int $size, string $sha256, string $mime): StoredObject
    {
        if ($this->failPut) {
            throw new StorageUnavailable;
        }
        $bytes = stream_get_contents($stream);
        if ($bytes === false || strlen($bytes) !== $size || hash('sha256', $bytes) !== $sha256) {
            throw new StorageConflict;
        }
        if (isset($this->objects[$key])) {
            if ($this->objects[$key]['object']->sha256 !== $sha256) {
                throw new StorageConflict;
            }

            return $this->objects[$key]['object'];
        }
        $object = new StoredObject($key, 'version-'.bin2hex(random_bytes(8)), $size, $sha256, $mime);
        $this->objects[$key] = ['object' => $object, 'bytes' => $bytes, 'created' => new DateTimeImmutable];
        ($this->afterPut ?? static function (): void {})();

        return $object;
    }

    public function openVerified(StoredObject $object): mixed
    {
        if (! isset($this->objects[$object->key]) || $this->objects[$object->key]['object'] != $object) {
            throw new StorageUnavailable;
        }
        $stream = tmpfile();
        if ($stream === false) {
            throw new StorageUnavailable;
        }
        fwrite($stream, $this->objects[$object->key]['bytes']);
        rewind($stream);
        ($this->afterOpen ?? static function (): void {})();

        return $stream;
    }

    public function statVersion(string $key, string $versionId): StoredObject
    {
        $object = $this->objects[$key]['object'] ?? throw new StorageUnavailable;
        if ($object->versionId !== $versionId) {
            throw new StorageUnavailable;
        }

        return $object;
    }

    public function deleteVersion(string $key, string $versionId): void
    {
        if ($this->failDelete) {
            throw new StorageUnavailable;
        }
        if (($this->objects[$key]['object']->versionId ?? null) === $versionId) {
            unset($this->objects[$key]);
        }
    }

    public function versions(?string $cursor = null, int $limit = 100): ObjectPage
    {
        return new ObjectPage(array_slice(array_map(fn (array $row): ObjectVersion => new ObjectVersion($row['object']->key,
            $row['object']->versionId, $row['object']->size, $row['created']), array_values($this->objects)), 0, $limit), null);
    }
}
