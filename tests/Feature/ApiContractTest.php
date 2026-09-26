<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ApiContractTest extends TestCase
{
    public function test_every_frontend_route_matches_the_reviewed_contract(): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $actual = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.$route->uri();
            if (! str_starts_with($uri, '/api/v1') && $uri !== '/sanctum/csrf-cookie') {
                self::assertContains($uri, ['/health/live', '/health/ready']);

                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[] = $method.' '.$uri;
            }
        }

        $documented = [];
        foreach ($spec['paths'] as $uri => $path) {
            foreach ($path as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options'], true)) {
                    $documented[] = strtoupper($method).' '.$uri;
                    foreach (['operationId', 'security', 'x-personas', 'x-permissions', 'x-frontend-feature', 'x-source', 'responses'] as $field) {
                        self::assertArrayHasKey($field, $operation, strtoupper($method).' '.$uri);
                    }
                }
            }
        }
        sort($actual);
        sort($documented);
        self::assertSame($actual, $documented, 'A route was added, removed or renamed without updating the frontend contract.');
        self::assertCount(count(array_unique($documented)), $documented);
    }

    public function test_all_frozen_error_statuses_keep_safe_codes_and_request_correlation(): void
    {
        $expected = [400 => 'MALFORMED_REQUEST', 401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN',
            404 => 'NOT_FOUND', 409 => 'CONFLICT', 412 => 'STALE_VERSION', 413 => 'REQUEST_TOO_LARGE',
            415 => 'UNSUPPORTED_MEDIA_TYPE', 422 => 'VALIDATION_FAILED', 428 => 'PRECONDITION_REQUIRED',
            429 => 'RATE_LIMITED', 503 => 'SERVICE_UNAVAILABLE'];
        Route::get('/api/v1/contract-test/error/{status}', function (int $status): never {
            throw new HttpException($status, 'private SQL token /storage/key');
        });

        foreach ($expected as $status => $code) {
            $response = $this->getJson('/api/v1/contract-test/error/'.$status);
            $response->assertStatus($status)->assertJsonPath('error.code', $code)->assertHeader('X-Request-ID');
            self::assertSame($response->headers->get('X-Request-ID'), $response->json('request_id'));
            self::assertStringNotContainsString('private', $response->getContent());
            self::assertStringNotContainsString('/storage', $response->getContent());
        }
    }
}
