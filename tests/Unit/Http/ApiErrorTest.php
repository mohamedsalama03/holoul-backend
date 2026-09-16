<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Infrastructure\Http\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApiErrorTest extends TestCase
{
    public function test_private_validator_messages_and_malformed_field_names_are_not_returned(): void
    {
        $validator = new Validator(new Translator(new ArrayLoader, 'en'), [], []);
        $validator->errors()->merge([
            'name' => ['secret SQL SELECT /private/document'],
            'items.0.title' => ['private provider response'],
            '/private/file' => ['private'],
            str_repeat('a', 129) => ['private'],
        ]);
        $exception = new ValidationException($validator, errorBag: 'default');
        $request = Request::create('/api/v1/test', 'POST');
        $response = ApiError::render($exception, $request);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            'name' => ['This field is invalid.'],
            'items.0.title' => ['This field is invalid.'],
        ], $body['error']['fields']);
        self::assertStringNotContainsString('private', $response->getContent());
        self::assertStringNotContainsString('secret', $response->getContent());
        self::assertStringNotContainsString('SELECT', $response->getContent());
    }

    public function test_error_field_count_is_bounded(): void
    {
        $validator = new Validator(new Translator(new ArrayLoader, 'en'), [], []);
        $messages = [];

        for ($i = 0; $i < 150; $i++) {
            $messages['field_'.$i] = ['private'];
        }

        $validator->errors()->merge($messages);
        $response = ApiError::render(new ValidationException($validator), Request::create('/api/v1/test'));
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(100, $body['error']['fields']);
    }

    public function test_untrusted_correlation_attributes_and_exception_headers_cannot_leak(): void
    {
        $request = Request::create('/api/v1/test');
        $request->attributes->set('request_id', 'secret-token');
        $exception = new HttpException(429, 'secret', headers: ['Retry-After' => 'secret-token', 'Allow' => 'secret-document']);
        $response = ApiError::render($exception, $request);

        self::assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertFalse($response->headers->has('Allow'));
        self::assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_bounded_protocol_headers_survive(): void
    {
        $response = ApiError::render(
            new HttpException(405, headers: ['Allow' => 'GET, HEAD', 'Retry-After' => '60']),
            Request::create('/api/v1/test'),
        );

        self::assertSame('GET, HEAD', $response->headers->get('Allow'));
        self::assertSame('60', $response->headers->get('Retry-After'));
    }
}
