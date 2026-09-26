<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

trait RecordsContractResponses
{
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $destination = getenv('HOLOUL_CONTRACT_CAPTURE');
        $path = parse_url($uri, PHP_URL_PATH);

        // Opt-in only in the disposable PostgreSQL test environment. Never record
        // request bodies, cookies or authorization headers. Response samples can
        // contain synthetic MFA/recovery material: keep them private and ignored.
        if (is_string($destination) && $destination !== '' && app()->environment('testing')
            && is_string($path) && (str_starts_with($path, '/api/v1') || $path === '/sanctum/csrf-cookie')) {
            $json = str_contains((string) $response->headers->get('Content-Type'), 'application/json');
            $body = $response->getContent();
            $record = ['test' => static::class.'::'.$this->name(), 'method' => strtoupper($method),
                'path' => $path, 'status' => $response->getStatusCode(),
                'headers' => array_intersect_key($response->headers->all(), array_flip(['content-type', 'etag', 'location', 'x-request-id', 'retry-after', 'cache-control', 'content-disposition', 'x-content-type-options'])),
                'body_length' => is_string($body) ? strlen($body) : null,
                'json' => $json, 'body' => $json ? json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR) : null];
            if (file_put_contents($destination, json_encode($record, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Unable to record contract verification sample.');
            }
        }

        return $response;
    }
}
