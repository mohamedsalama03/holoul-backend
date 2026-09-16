<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class HttpFoundationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_version_envelope_and_approved_identity_entrypoint_are_explicit(): void
    {
        $this->getJson('/api/v1')->assertOk()->assertExactJson([
            'data' => ['service' => 'HOLOUL', 'api_version' => 'v1'],
        ])->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/sanctum/csrf-cookie')->assertNoContent()->assertHeader('X-Request-ID');
        $this->getJson('http://localhost:8080/sanctum/csrf-cookie')->assertForbidden();
        $this->getJson('/login')->assertNotFound();
    }

    public function test_valid_request_ids_propagate_and_untrusted_values_are_replaced(): void
    {
        $id = (string) Str::uuid7();
        $this->withHeader('X-Request-ID', $id)->getJson('/api/v1')->assertHeader('X-Request-ID', $id);
        $response = $this->withHeader('X-Request-ID', 'private-payload')->getJson('/missing');
        $response->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        self::assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
        self::assertSame($response->headers->get('X-Request-ID'), $response->json('request_id'));
    }

    public function test_unknown_routes_and_methods_use_safe_json_even_without_accept_header(): void
    {
        $this->get('/missing')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->postJson('/api/v1')->assertStatus(405)->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    }

    public function test_exceptions_never_expose_private_details_even_when_debug_is_requested(): void
    {
        Config::set('app.debug', true);
        Route::get('/api/v1/test-failure', function (): never {
            throw new RuntimeException('secret-password private-document SELECT * /var/www/internal.php');
        });
        $response = $this->getJson('/api/v1/test-failure');
        $response->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        foreach (['secret-password', 'private-document', 'SELECT', '/var/www', 'trace', 'exception'] as $private) {
            self::assertStringNotContainsString($private, $response->getContent());
        }
    }

    public function test_validation_is_normalized(): void
    {
        Route::post('/api/v1/test-validation', function (): array {
            return request()->validate(['name' => ['required', 'string', 'max:20']]);
        });
        $this->postJson('/api/v1/test-validation', [])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors('name', 'error.fields');
    }

    public function test_framework_csrf_errors_are_normalized(): void
    {
        Route::post('/api/v1/test-csrf', function (): never {
            throw new TokenMismatchException('private token detail');
        });
        $this->postJson('/api/v1/test-csrf')->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_malformed_json_and_unsupported_content_types_are_rejected(): void
    {
        $this->call('POST', '/api/v1', server: ['CONTENT_TYPE' => 'application/json'], content: '{')
            ->assertStatus(400)->assertJsonPath('error.code', 'MALFORMED_REQUEST');
        $this->call('POST', '/api/v1', server: ['CONTENT_TYPE' => 'text/plain'], content: 'text')
            ->assertStatus(415);
    }

    public function test_body_limit_is_enforced_before_route_execution(): void
    {
        $this->call('POST', '/api/v1', server: ['CONTENT_TYPE' => 'application/json'], content: str_repeat('x', 12 * 1024 * 1024 + 1))
            ->assertStatus(413);
    }

    public function test_liveness_and_readiness_do_not_depend_on_redis(): void
    {
        Config::set('database.redis.default.port', '1');
        Config::set('database.redis.cache.port', '1');
        $this->getJson('/health/live')->assertOk()->assertExactJson(['data' => ['status' => 'alive']]);
        $this->getJson('/health/ready')->assertOk()->assertExactJson(['data' => ['status' => 'ready']]);
    }

    public function test_readiness_fails_safely_when_postgresql_is_unavailable_but_liveness_passes(): void
    {
        $port = Config::get('database.connections.pgsql.port');
        Config::set('database.connections.pgsql.port', '1');
        DB::purge();
        try {
            $this->getJson('/health/live')->assertOk();
            $response = $this->getJson('/health/ready')->assertStatus(503);
            $response->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
            self::assertStringNotContainsString('pgsql', $response->getContent());
            self::assertStringNotContainsString('password', $response->getContent());
        } finally {
            Config::set('database.connections.pgsql.port', $port);
            DB::purge();
        }
    }
}
