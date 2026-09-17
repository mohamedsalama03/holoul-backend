<?php

declare(strict_types=1);

namespace App\Modules\Documents\Contracts;

use App\Modules\Documents\Data\ObjectPage;
use App\Modules\Documents\Data\StoredObject;

interface PrivateObjectStore
{
    /** @param resource $stream */
    public function putIfAbsent(string $key, mixed $stream, int $size, string $sha256, string $mime): StoredObject;

    /** @return resource Bounded private stream, with exact version/size/checksum verified. */
    public function openVerified(StoredObject $object): mixed;

    public function statVersion(string $key, string $versionId): StoredObject;

    /** Idempotent for an already absent exact version. */
    public function deleteVersion(string $key, string $versionId): void;

    public function versions(?string $cursor = null, int $limit = 100): ObjectPage;
}
