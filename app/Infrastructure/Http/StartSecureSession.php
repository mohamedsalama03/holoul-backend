<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/** Laravel 13 StartSession lifecycle with explicit persistence/cookie disposition. */
final class StartSecureSession extends StartSession
{
    /**
     * @param  Session  $session
     * @param  Closure(Request): Response  $next
     */
    protected function handleStatefulRequest(Request $request, $session, Closure $next): Response
    {
        SessionResponse::begin($request, $session->getId(), is_string($request->cookies->get($session->getName())));
        $request->setLaravelSession($this->startSession($request, $session));
        $this->collectGarbage($session);

        $response = $next($request);

        if (SessionResponse::persist($request, $response)) {
            $this->storeCurrentUrl($request, $session);
            $this->saveSession($request);
            // Reissuing an unchanged ID lets a delayed successful read undo a rotation too.
            if (SessionResponse::sessionCookie($request, $response)) {
                $this->addCookieToResponse($response, $session);
            }
        }

        return $response;
    }
}
