<?php

declare(strict_types=1);

namespace App\Modules\Documents\Adapters;

use Aws\S3\S3Client;
use InvalidArgumentException;

final class S3AdapterFactory
{
    public static function create(string $endpoint, string $region, string $bucket, string $accessKey, string $secretKey, string $caBundle): S3PrivateObjectStore
    {
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || $accessKey === '' || $secretKey === '' || ! is_file($caBundle)) {
            throw new InvalidArgumentException('Private object storage requires verified TLS and explicit credentials.');
        }

        return new S3PrivateObjectStore(new S3Client([
            'version' => '2006-03-01', 'region' => $region, 'endpoint' => $endpoint,
            'use_path_style_endpoint' => true, 'credentials' => ['key' => $accessKey, 'secret' => $secretKey],
            'retries' => 1, 'http' => ['verify' => $caBundle, 'connect_timeout' => 3, 'timeout' => 20, 'allow_redirects' => false],
            'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
        ]), $bucket);
    }
}
