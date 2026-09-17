<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ExactOrigin
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $trusted = rtrim(Config::string('identity.origin'), '/');
        // Require HTTPS for this session surface in every environment; local Compose supplies TLS.
        if (! str_starts_with($trusted, 'https://') || ! $request->isSecure() || $request->getSchemeAndHttpHost() !== $trusted) {
            throw new HttpException(403);
        }
        $origin = $request->headers->get('Origin');
        $referer = $request->headers->get('Referer');
        if (($origin !== null && $origin !== $trusted)
            || ($referer !== null && $this->origin($referer) !== $trusted)
            || (! $request->isMethodSafe() && $origin === null && $referer === null)
            || $request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            throw new HttpException(403);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
