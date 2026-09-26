<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Infrastructure\Http\SessionResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** B0 requires a CSRF token even when Sec-Fetch-Site says same-origin, including tests. */
final class StrictCsrf extends PreventRequestForgery
{
    /** @param Request $request
     * @param  Response  $response
     */
    protected function addCookieToResponse($request, $response): Response
    {
        // A late XSRF token can invalidate the newer browser's next mutation as well.
        return SessionResponse::xsrfCookie($request, $response)
            ? parent::addCookieToResponse($request, $response) : $response;
    }

    protected function runningUnitTests(): bool
    {
        return false;
    }

    /** @param Request $request */
    protected function hasValidOrigin($request): bool
    {
        return false;
    }
}
