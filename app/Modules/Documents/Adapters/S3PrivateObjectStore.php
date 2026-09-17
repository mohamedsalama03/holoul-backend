<?php

declare(strict_types=1);

namespace App\Modules\Documents\Adapters;

use App\Modules\Documents\Contracts\PrivateObjectStore;
use App\Modules\Documents\Data\ObjectPage;
use App\Modules\Documents\Data\ObjectVersion;
use App\Modules\Documents\Data\StoredObject;
use App\Modules\Documents\Exceptions\StorageConflict;
use App\Modules\Documents\Exceptions\StorageUnavailable;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3Client;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

final readonly class S3PrivateObjectStore implements PrivateObjectStore
{
    private const MAX_BYTES = 10485760;

    public function __construct(private S3Client $client, private string $bucket) {}

    public function putIfAbsent(string $key, mixed $stream, int $size, string $sha256, string $mime): StoredObject
    {
        $expected = new StoredObject($key, 'pending', $size, $sha256, $mime);
        $this->validate($expected);
        $binaryHash = hex2bin($sha256);
        if ($binaryHash === false) {
            throw new StorageUnavailable;
        }
        if (! is_resource($stream) || rewind($stream) === false) {
            throw new StorageUnavailable;
        }
        // Validate even when called outside the upload action. Never send different bytes under a claimed hash.
        $hash = hash_init('sha256');
        $count = hash_update_stream($hash, $stream, self::MAX_BYTES + 1);
        if ($count !== $size || ! feof($stream) && fread($stream, 1) !== '' || ! hash_equals($sha256, hash_final($hash)) || ! rewind($stream)) {
            throw new StorageConflict;
        }
        try {
            $result = $this->client->putObject([
                'Bucket' => $this->bucket, 'Key' => $key, 'Body' => $stream, 'ContentLength' => $size,
                'ContentType' => $mime, 'IfNoneMatch' => '*', 'ChecksumSHA256' => base64_encode($binaryHash),
                'Metadata' => ['sha256' => $sha256, 'mime' => $mime], 'ServerSideEncryption' => 'AES256',
            ]);
            $version = $result->get('VersionId');
            if (! is_string($version) || $version === '' || $version === 'null') {
                throw new StorageUnavailable;
            }

            return new StoredObject($key, $version, $size, $sha256, $mime);
        } catch (AwsException $error) {
            if ($error->getStatusCode() !== 412) {
                throw new StorageUnavailable;
            }
            try {
                $current = $this->describe($key, $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]));
                if ($current->size !== $size || $current->mime !== $mime || ! hash_equals($current->sha256, $sha256)) {
                    throw new StorageConflict;
                }
                $verified = $this->openVerified($current);
                fclose($verified);

                return $current;
            } catch (StorageConflict $conflict) {
                throw $conflict;
            } catch (Throwable) {
                throw new StorageUnavailable;
            }
        } catch (Throwable) {
            throw new StorageUnavailable;
        }
    }

    public function openVerified(StoredObject $object): mixed
    {
        $this->validate($object);
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new StorageUnavailable;
        }
        try {
            $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $object->key, 'VersionId' => $object->versionId, '@http' => ['stream' => true]]);
            $actual = $this->describe($object->key, $result);
            $body = $result->get('Body');
            if (! $body instanceof StreamInterface || $actual != $object) {
                throw new StorageUnavailable;
            }
            $hash = hash_init('sha256');
            $count = 0;
            try {
                while ($count < $object->size) {
                    $chunk = $body->read(min(65536, $object->size - $count));
                    $count += strlen($chunk);
                    if ($chunk === '' || $count > $object->size || fwrite($temporary, $chunk) !== strlen($chunk)) {
                        throw new StorageUnavailable;
                    }
                    hash_update($hash, $chunk);
                }
                if ($body->read(1) !== '') {
                    throw new StorageUnavailable;
                }
            } finally {
                $body->close();
            }
            if ($count !== $object->size || ! hash_equals($object->sha256, hash_final($hash)) || ! rewind($temporary)) {
                throw new StorageUnavailable;
            }

            return $temporary;
        } catch (Throwable) {
            fclose($temporary);
            throw new StorageUnavailable;
        }
    }

    public function statVersion(string $key, string $versionId): StoredObject
    {
        try {
            $object = $this->describe($key, $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key, 'VersionId' => $versionId]));
            if ($object->versionId !== $versionId) {
                throw new StorageUnavailable;
            }

            return $object;
        } catch (Throwable) {
            throw new StorageUnavailable;
        }
    }

    public function deleteVersion(string $key, string $versionId): void
    {
        if ($key === '' || $versionId === '' || $versionId === 'null') {
            throw new StorageUnavailable;
        }
        try {
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key, 'VersionId' => $versionId]);
        } catch (AwsException $error) {
            if ($error->getStatusCode() !== 404) {
                throw new StorageUnavailable;
            }
        } catch (Throwable) {
            throw new StorageUnavailable;
        }
    }

    public function versions(?string $cursor = null, int $limit = 100): ObjectPage
    {
        try {
            $arguments = ['Bucket' => $this->bucket, 'MaxKeys' => max(1, min(100, $limit))];
            if ($cursor !== null) {
                $markers = json_decode(base64_decode($cursor, true) ?: '', true, 4, JSON_THROW_ON_ERROR);
                if (! is_array($markers) || ! is_string($markers['key'] ?? null) || ! is_string($markers['version'] ?? null)) {
                    throw new StorageUnavailable;
                }
                $arguments['KeyMarker'] = $markers['key'];
                $arguments['VersionIdMarker'] = $markers['version'];
            }
            $result = $this->client->listObjectVersions($arguments);
            $versions = $result->get('Versions') ?? [];
            if (! is_array($versions) || count($versions) > 100) {
                throw new StorageUnavailable;
            }
            $objects = [];
            foreach ($versions as $entry) {
                if (! is_array($entry) || ! is_string($entry['Key'] ?? null) || ! is_string($entry['VersionId'] ?? null) || ! ($entry['LastModified'] ?? null) instanceof DateTimeInterface) {
                    throw new StorageUnavailable;
                }
                $size = $entry['Size'] ?? null;
                // The SDK preserves XML long values as strings. Validate before narrowing.
                if (is_string($size) && ctype_digit($size) && strlen($size) <= strlen((string) PHP_INT_MAX)
                    && (strlen($size) < strlen((string) PHP_INT_MAX) || strcmp($size, (string) PHP_INT_MAX) <= 0)) {
                    $size = (int) $size;
                }
                if (! is_int($size) || $size < 0) {
                    throw new StorageUnavailable;
                }
                $objects[] = new ObjectVersion($entry['Key'], $entry['VersionId'], $size, DateTimeImmutable::createFromInterface($entry['LastModified']));
            }
            $next = null;
            if ($result->get('IsTruncated') === true) {
                $key = $result->get('NextKeyMarker');
                $version = $result->get('NextVersionIdMarker');
                if (! is_string($key) || ! is_string($version)) {
                    throw new StorageUnavailable;
                }
                $next = base64_encode(json_encode(['key' => $key, 'version' => $version], JSON_THROW_ON_ERROR));
            }

            return new ObjectPage($objects, $next);
        } catch (Throwable) {
            throw new StorageUnavailable;
        }
    }

    /** @param Result<array-key, mixed> $result */
    private function describe(string $key, Result $result): StoredObject
    {
        $metadata = $result->get('Metadata');
        $version = $result->get('VersionId');
        $size = $result->get('ContentLength');
        $mime = $result->get('ContentType');
        if (! is_array($metadata) || ! is_string($metadata['sha256'] ?? null) || ! is_string($metadata['mime'] ?? null) || ! is_string($version) || ! is_int($size) || ! is_string($mime) || $metadata['mime'] !== $mime) {
            throw new StorageUnavailable;
        }
        $object = new StoredObject($key, $version, $size, $metadata['sha256'], $mime);
        $this->validate($object);

        return $object;
    }

    private function validate(StoredObject $object): void
    {
        if ($object->key === '' || strlen($object->key) > 512 || $object->versionId === '' || $object->versionId === 'null' || $object->size < 1 || $object->size > self::MAX_BYTES || preg_match('/\A[a-f0-9]{64}\z/', $object->sha256) !== 1 || ! in_array($object->mime, ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
            throw new StorageUnavailable;
        }
    }
}
