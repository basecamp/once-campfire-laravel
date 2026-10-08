<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * Laravel's StartSession, minus the per-request churn on unchanged reads: when the request
 * carried the current session id, nothing in the session changed and the cookie/file were
 * refreshed within the last half lifetime, neither the Set-Cookie nor the session file write is
 * repeated. Expiry still slides: past half the lifetime both are reissued. Authentication does not
 * use this session (AuthenticateCampfire reads the Rails session row on every request).
 */
final class StartSession extends BaseStartSession
{
    private const REFRESHED = '_campfire_session_refreshed_at';

    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        $request->setLaravelSession($this->startSession($request, $session));
        $this->collectGarbage($session);

        $requestedId = $request->cookies->get($session->getName());
        $resumed = is_string($requestedId) && $session->getId() === $requestedId;
        $before = $resumed ? serialize($session->all()) : null;

        $response = $next($request);

        $this->storeCurrentUrl($request, $session);

        $refreshed = $session->get(self::REFRESHED);
        $current = $resumed && $session->getId() === $requestedId
            && is_int($refreshed) && $refreshed > time() - intdiv($this->getSessionLifetimeInSeconds(), 2);
        if (! $current) {
            $session->put(self::REFRESHED, time());
            $this->addCookieToResponse($response, $session);
        }

        $unchanged = $current && $before === serialize($session->all())
            && empty($session->get('_flash.new')) && empty($session->get('_flash.old')) && $session->missing('errors');
        if (! $unchanged) {
            $this->saveSession($request);
        }

        return $response;
    }
}
