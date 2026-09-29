<?php

declare(strict_types=1);

namespace App\Application\PublicServices;

use App\Modules\PublicPortfolio\PortfolioPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Outside ExactOrigin on exactly the four anonymous portfolio GET routes. */
final class PublicPortfolioCache
{
    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (in_array($request->method(), ['GET', 'HEAD'], true) && $response->getStatusCode() === 200 && $response->headers->getCookies() === []) {
            $response->headers->set('Cache-Control', PortfolioPolicy::CACHE_CONTROL);
        }

        return $response;
    }
}
