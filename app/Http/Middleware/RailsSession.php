<?php

namespace App\Http\Middleware;

use App\Support\RailsCrypto;

final class RailsSession
{
    public function handle($request, \Closure $next)
    {
        $crypto = app(RailsCrypto::class);
        $payload = $crypto->decryptCookie('_campfire_session', $request->cookie('_campfire_session'));
        $originalPayload = is_array($payload) ? $payload : null;
        if (isset($payload['return_to_after_authenticating'])) {
            $request->session()->put('return_to', $payload['return_to_after_authenticating']);
        }
        $response = $next($request);
        $payload = is_array($payload) ? $payload : [];
        $payload['session_id'] = $payload['session_id'] ?? bin2hex(random_bytes(16));
        if ($request->session()->has('return_to')) {
            $payload['return_to_after_authenticating'] = $request->session()->get('return_to');
        } else {
            unset($payload['return_to_after_authenticating']);
        }
        // Keep unchanged Rails cookies byte-identical across requests.
        if ($originalPayload !== null && $payload === $originalPayload) {
            return $response;
        }
        $response->headers->setCookie(cookie('_campfire_session', $crypto->encryptCookie('_campfire_session', $payload), 0, '/', null, $request->isSecure(), true, false, 'lax'));

        return $response;
    }
}
