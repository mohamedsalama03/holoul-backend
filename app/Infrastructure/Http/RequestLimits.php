<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RequestLimits
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('PUT') && preg_match('#\Aapi/v1/(?:project-requests|admin/projects)/[0-9a-f-]{36}/documents/[0-9a-f-]{36}/content\z#D', $request->path()) === 1) {
            if ($request->header('Content-Type') !== 'application/octet-stream') {
                throw new HttpException(415);
            }
            if ((int) $request->header('Content-Length', '0') > Config::integer('documents.max_bytes')) {
                throw new HttpException(413);
            }

            // Do not materialize raw document bytes; StoreUpload independently
            // bounds the stream, including chunked/misdeclared request bodies.
            return $next($request);
        }
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
