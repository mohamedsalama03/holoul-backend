<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Adapters;

use App\Modules\PublicPortfolio\Contracts\PortfolioStorage;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

final class PortfolioStorageFactory
{
    public static function create(): PortfolioStorage
    {
        $endpoint = Config::string('documents.s3.endpoint');
        $caBundle = Config::string('documents.s3.ca_bundle');
        $accessKey = Config::string('documents.s3.access_key');
        $secretKey = Config::string('documents.s3.secret_key');
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || ! is_file($caBundle) || $accessKey === '' || $secretKey === '') {
            throw new InvalidArgumentException('Portfolio storage requires verified private TLS.');
        }

        return new S3PortfolioStorage(new S3Client([
            'version' => '2006-03-01', 'region' => Config::string('documents.s3.region'), 'endpoint' => $endpoint,
            'use_path_style_endpoint' => true, 'credentials' => ['key' => $accessKey, 'secret' => $secretKey],
            'retries' => 1, 'http' => ['verify' => $caBundle, 'connect_timeout' => 3, 'timeout' => 20, 'allow_redirects' => false],
            'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
        ]), Config::string('documents.s3.bucket'));
    }
}
