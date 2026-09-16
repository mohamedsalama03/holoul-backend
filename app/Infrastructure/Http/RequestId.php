<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestId
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->header('X-Request-ID');
        $id = is_string($candidate) && Str::isUuid($candidate) ? $candidate : (string) Str::uuid7();
        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id]);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        Log::withoutContext();
    }
}
