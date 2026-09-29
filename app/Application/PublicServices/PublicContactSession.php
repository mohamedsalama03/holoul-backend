<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Infrastructure\Http\SessionResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicContactSession
{
    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Contact accepts any persona but never persists or replaces authenticated state.
        SessionResponse::discard($request);

        return $next($request);
    }
}
