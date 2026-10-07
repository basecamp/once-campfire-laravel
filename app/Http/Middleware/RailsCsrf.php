<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RailsCrypto;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

final class RailsCsrf extends PreventRequestForgery
{
    public function handle($request, \Closure $next)
    {
        $crypto = app(RailsCrypto::class);
        $payload = $crypto->decryptCookie('_campfire_session', $request->cookie('_campfire_session'));
        $originalPayload = is_array($payload) ? $payload : null;
        if (is_array($payload)) {
            if (isset($payload['_csrf_token'])) {
                $request->session()->put('_token', $payload['_csrf_token']);
            }
            if (isset($payload['return_to_after_authenticating'])) {
                $request->session()->put('return_to', $payload['return_to_after_authenticating']);
            }
        }
        if (! $request->session()->has('_campfire_raw_csrf')) {
            $request->session()->put('_campfire_raw_csrf', base64_encode(random_bytes(32)));
        }
        if (! is_array($payload) || ! isset($payload['_csrf_token'])) {
            $request->session()->put('_token', $request->session()->get('_campfire_raw_csrf'));
        }
        if ($request->has('authenticity_token') && ! $request->has('_token')) {
            $request->merge(['_token' => $request->input('authenticity_token')]);
        }
        $response = parent::handle($request, $next);
        $decoded = base64_decode(strtr($request->session()->token(), '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) !== 32) {
            $request->session()->put('_token', base64_encode(random_bytes(32)));
        }
        $payload = is_array($payload) ? $payload : [];
        $payload['_csrf_token'] = $request->session()->token();
        $payload['session_id'] = $payload['session_id'] ?? bin2hex(random_bytes(16));
        if ($request->session()->has('return_to')) {
            $payload['return_to_after_authenticating'] = $request->session()->get('return_to');
        } else {
            unset($payload['return_to_after_authenticating']);
        }
        // Opt 3: skip re-encrypt/setCookie when plaintext payload is unchanged.
        if ($originalPayload !== null && $payload === $originalPayload) {
            return $response;
        }
        $response->headers->setCookie(cookie('_campfire_session', $crypto->encryptCookie('_campfire_session', $payload), 0, '/', null, $request->isSecure(), true, false, 'lax'));

        return $response;
    }

    protected function inExceptArray($request)
    {
        if ($request->isMethod('PUT') && preg_match('~^rails/active_storage/disk/([^/]+)$~', $request->path(), $disk)) {
            return is_array(app(RailsCrypto::class)->appVerify($disk[1], 'blob_token'));
        }
        if (preg_match('~^rooms/\d+/([^/]+)/messages(?:/\d+(?:/boosts(?:/\d+)?)?)?$~', $request->path(), $m)) {
            $parts = explode('-', trim($m[1]), 2);

            return count($parts) === 2 && User::active()->where('role', 2)->where('bot_token', $parts[1])->where('id', $parts[0])->exists();
        }

        return parent::inExceptArray($request);
    }

    protected function tokensMatch($request)
    {
        if (parent::tokensMatch($request)) {
            return true;
        }
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');
        if (! is_string($token)) {
            return false;
        }
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $secret = base64_decode(strtr($request->session()->token(), '-_', '+/'), true);
        if ($decoded === false || $secret === false || strlen($secret) !== 32) {
            return false;
        }
        if (strlen($decoded) === 32) {
            return hash_equals($secret, $decoded);
        }
        if (strlen($decoded) === 64) {
            $decoded = substr($decoded, 0, 32) ^ substr($decoded, 32);
        }
        if (strlen($decoded) !== 32) {
            return false;
        }
        if (hash_equals($secret, $decoded) || hash_equals(hash_hmac('sha256', '!real_csrf_token', $secret, true), $decoded)) {
            return true;
        }
        $action = rtrim($request->getPathInfo(), '/').'#'.strtolower($request->method());

        return hash_equals(hash_hmac('sha256', $action, $secret, true), $decoded);
    }
}
