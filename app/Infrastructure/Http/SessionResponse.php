<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Request-local disposition; never a cookie, session attribute or client-supplied flag. */
final class SessionResponse
{
    private const STATE = 'holoul.session.response';

    private function __construct(private readonly string $initialId, private readonly bool $hadCookie,
        private bool $discarded = false) {}

    public static function begin(Request $request, string $id, bool $hadCookie): void
    {
        // FormRequest copies the attribute bag. Share this object with the outer middleware.
        $request->attributes->set(self::STATE, new self($id, $hadCookie));
    }

    public static function discard(Request $request): void
    {
        $state = $request->attributes->get(self::STATE);
        if ($state instanceof self) {
            $state->discarded = true;
        }
    }

    public static function persist(Request $request, Response $response): bool
    {
        $state = $request->attributes->get(self::STATE);

        return $state instanceof self && $response->getStatusCode() < 400 && ! $state->discarded
            && ($state->hadCookie || self::rotated($request) || self::bootstrap($request));
    }

    public static function sessionCookie(Request $request, Response $response): bool
    {
        $state = $request->attributes->get(self::STATE);

        return $state instanceof self && self::persist($request, $response)
            && (self::rotated($request) || (self::bootstrap($request) && ! $state->hadCookie));
    }

    public static function xsrfCookie(Request $request, Response $response): bool
    {
        $incoming = $request->cookies->get('XSRF-TOKEN');
        $token = $request->session()->token();

        return self::persist($request, $response) && (self::rotated($request)
            || (self::bootstrap($request) && (! is_string($incoming) || ! hash_equals($token, $incoming))));
    }

    private static function rotated(Request $request): bool
    {
        $state = $request->attributes->get(self::STATE);

        return $state instanceof self && $request->session()->getId() !== $state->initialId;
    }

    private static function bootstrap(Request $request): bool
    {
        return $request->isMethod('GET') && $request->routeIs('sanctum.csrf-cookie');
    }
}
