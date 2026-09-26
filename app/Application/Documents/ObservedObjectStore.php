<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Infrastructure\Operations\MetricRecorder;
use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\ObjectPage;
use App\Modules\Documents\Data\StoredObject;

/** Times only public contract outcomes; object names, versions and contents never become labels. */
final readonly class ObservedObjectStore implements PrivateObjectStore
{
    public function __construct(private PrivateObjectStore $inner, private MetricRecorder $metrics) {}

    public function putIfAbsent(string $key, mixed $stream, int $size, string $sha256, string $mime): StoredObject
    {
        return $this->metrics->storage('put', fn (): StoredObject => $this->inner->putIfAbsent($key, $stream, $size, $sha256, $mime));
    }

    public function openVerified(StoredObject $object): mixed
    {
        return $this->metrics->storage('open', fn () => $this->inner->openVerified($object));
    }

    public function statVersion(string $key, string $versionId): StoredObject
    {
        return $this->metrics->storage('stat', fn (): StoredObject => $this->inner->statVersion($key, $versionId));
    }

    public function deleteVersion(string $key, string $versionId): void
    {
        $this->metrics->storage('delete', function () use ($key, $versionId): void {
            $this->inner->deleteVersion($key, $versionId);
        });
    }

    public function versions(?string $cursor = null, int $limit = 100): ObjectPage
    {
        return $this->metrics->storage('list', fn (): ObjectPage => $this->inner->versions($cursor, $limit));
    }
}
