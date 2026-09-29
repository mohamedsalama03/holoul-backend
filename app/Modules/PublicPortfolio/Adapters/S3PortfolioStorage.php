<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Adapters;

use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use App\Modules\PublicPortfolio\PortfolioPolicy;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Disjoint portfolio prefix; never accepts a document key or a public URL. */
final readonly class S3PortfolioStorage implements PortfolioStorage
{
    public function __construct(private S3Client $client, private string $bucket) {}

    public function put(string $assetId, string $variant, string $bytes): string
    {
        $key = $this->key($assetId, $variant);
        $size = strlen($bytes);
        if ($size < 1 || $size > PortfolioPolicy::MAX_BYTES) {
            throw new HttpException(413);
        }
        $sha = hash('sha256', $bytes);
        try {
            try {
                $result = $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $bytes,
                    'ContentLength' => $size, 'ContentType' => $variant === 'source' ? 'application/octet-stream' : 'image/webp',
                    'IfNoneMatch' => '*', 'Metadata' => ['sha256' => $sha], 'ChecksumSHA256' => base64_encode(hash('sha256', $bytes, true)), 'ServerSideEncryption' => 'AES256']);
            } catch (AwsException $error) {
                if ($error->getStatusCode() !== 412) {
                    throw $error;
                }
                $result = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
            }
            $version = $result->get('VersionId');
            if (! is_string($version) || $version === '' || $version === 'null') {
                throw new HttpException(503);
            }
            // A retry after an ambiguous storage write resolves only to identical bytes.
            $this->get($assetId, $variant, $version, $sha, $size);

            return $version;
        } catch (Throwable) {
            throw new HttpException(503);
        }
    }

    public function get(string $assetId, string $variant, string $version, string $sha256, int $size): string
    {
        $key = $this->key($assetId, $variant);
        if ($version === '' || $version === 'null' || $size < 1 || $size > PortfolioPolicy::MAX_BYTES || preg_match('/\A[0-9a-f]{64}\z/D', $sha256) !== 1) {
            throw new HttpException(503);
        }
        try {
            $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'VersionId' => $version, '@http' => ['stream' => true]]);
            $body = $result->get('Body');
            if (! $body instanceof StreamInterface) {
                throw new HttpException(503);
            }
            try {
                if ($result->get('VersionId') !== $version || $result->get('ContentLength') !== $size) {
                    throw new HttpException(503);
                }
                $bytes = '';
                while (strlen($bytes) < $size) {
                    $chunk = $body->read(min(65536, $size - strlen($bytes)));
                    if ($chunk === '') {
                        throw new HttpException(503);
                    }
                    $bytes .= $chunk;
                }
                if ($body->read(1) !== '' || ! hash_equals($sha256, hash('sha256', $bytes))) {
                    throw new HttpException(503);
                }

                return $bytes;
            } finally {
                $body->close();
            }
        } catch (Throwable) {
            throw new HttpException(503);
        }
    }

    public function purge(string $assetId): void
    {
        try {
            foreach (['source', 'card', 'gallery'] as $variant) {
                $key = $this->key($assetId, $variant);
                $result = $this->client->listObjectVersions(['Bucket' => $this->bucket, 'Prefix' => $key, 'MaxKeys' => 32]);
                if ($result->get('IsTruncated') === true) {
                    throw new HttpException(503);
                }
                foreach (['Versions', 'DeleteMarkers'] as $kind) {
                    $versions = $result->get($kind) ?? [];
                    if (! is_array($versions) || count($versions) > 32) {
                        throw new HttpException(503);
                    }
                    foreach ($versions as $entry) {
                        if (! is_array($entry) || ($entry['Key'] ?? null) !== $key || ! is_string($entry['VersionId'] ?? null)) {
                            throw new HttpException(503);
                        }
                        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key, 'VersionId' => $entry['VersionId']]);
                    }
                }
            }
        } catch (Throwable) {
            throw new HttpException(503);
        }
    }

    private function key(string $id, string $variant): string
    {
        if (! Str::isUuid($id, 7) || ! in_array($variant, ['source', 'card', 'gallery'], true)) {
            throw new HttpException(503);
        }

        return 'portfolio/assets/'.$id.'/'.$variant;
    }
}
