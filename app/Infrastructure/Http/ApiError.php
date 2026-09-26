<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiError
{
    public static function render(Throwable $exception, Request $request): JsonResponse
    {
        $status = match (true) {
            $exception instanceof ValidationException => 422,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException, $exception instanceof TokenMismatchException => 403,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
        $status = $status === 419 ? 403 : $status;
        $code = match ($status) {
            400 => 'MALFORMED_REQUEST', 401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN',
            404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 409 => 'CONFLICT',
            412 => 'STALE_VERSION', 413 => 'REQUEST_TOO_LARGE', 415 => 'UNSUPPORTED_MEDIA_TYPE',
            422 => 'VALIDATION_FAILED', 428 => 'PRECONDITION_REQUIRED', 429 => 'RATE_LIMITED',
            503 => 'SERVICE_UNAVAILABLE', default => 'INTERNAL_ERROR',
        };
        $message = match ($status) {
            422 => 'The submitted fields are invalid.',
            401 => 'Authentication is required.',
            403 => 'This action is not permitted.',
            404 => 'The resource was not found.',
            429 => 'Too many requests. Please retry later.',
            503 => 'The service is temporarily unavailable.',
            default => $status >= 500 ? 'An unexpected error occurred.' : 'The request could not be completed.',
        };
        $id = $request->attributes->get('request_id');
        $id = is_string($id) && Str::isUuid($id) ? $id : (string) Str::uuid7();
        $body = ['error' => ['code' => $code, 'message' => $message], 'request_id' => $id];

        if ($exception instanceof ValidationException) {
            // Validator messages may include custom provider errors or submitted
            // values. Return a bounded field map, never arbitrary exception text.
            $fields = [];

            foreach (array_slice($exception->errors(), 0, 100, true) as $field => $messages) {
                if (is_string($field) && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.]{0,127}\z/', $field) === 1) {
                    $fields[$field] = ['This field is invalid.'];
                }
            }

            $body['error']['fields'] = (object) $fields;
        }

        $headers = ['X-Request-ID' => $id, 'Cache-Control' => 'no-store'];

        if ($exception instanceof HttpExceptionInterface) {
            $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;

            $retryDate = is_string($retryAfter) && strlen($retryAfter) === 29
                ? DateTimeImmutable::createFromFormat(DATE_RFC7231, $retryAfter) : false;

            if (is_string($retryAfter) && (preg_match('/\A[0-9]{1,6}\z/', $retryAfter) === 1
                || ($retryDate !== false && $retryDate->format(DATE_RFC7231) === $retryAfter))) {
                $headers['Retry-After'] = $retryAfter;
            }

            $allow = $exception->getHeaders()['Allow'] ?? null;

            if (is_string($allow) && strlen($allow) <= 128
                && preg_match('/\A(?:GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS)(?:, *(?:GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS))*\z/', $allow) === 1) {
                $headers['Allow'] = $allow;
            }
        }

        return new JsonResponse($body, $status, $headers);
    }
}
