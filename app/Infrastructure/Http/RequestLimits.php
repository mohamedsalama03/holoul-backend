<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RequestLimits
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (strlen($request->getContent()) > 12 * 1024 * 1024) {
            throw new HttpException(413);
        }

        if ($request->getContent() !== '' && $request->is('api/*')) {
            if (! $request->isJson()) {
                throw new HttpException(415);
            }

            try {
                json_decode($request->getContent(), associative: true, depth: 32, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new HttpException(400);
            }
        }

        return $next($request);
    }
}
