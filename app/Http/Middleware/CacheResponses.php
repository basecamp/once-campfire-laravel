<?php

namespace App\Http\Middleware;

use App\Support\ResponseCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CacheResponses
{
    public static function eligible(Request $request): bool
    {
        return $request->isMethod('GET') && ! $request->expectsJson()
            && ! $request->headers->has('If-None-Match') && ! $request->headers->has('If-Modified-Since')
            && preg_match('~^/(?:rooms/\d+(?:/@\d+|/messages)?|users/(?:me|\d+)/sidebar|searches)$~', $request->getPathInfo());
    }

    public function handle(Request $request, Closure $next): Response
    {
        $epoch = $request->attributes->get('campfire.response_epoch');
        if ($epoch === null || ! self::eligible($request)
            || $request->session()->hasAny(['notice', 'alert', 'errors', '_old_input'])) {
            return $next($request);
        }
        // Authorization is never supplied by a cached response.
        $room = null;
        if (preg_match('~^/rooms/(\d+)~', $request->getPathInfo(), $match)) {
            $room = $request->user()->rooms()->findOrFail((int) $match[1]);
            $request->attributes->set('campfire.authorized_room', $room);
        }
        $cache = app(ResponseCache::class);
        $key = hash('sha256', json_encode([
            $request->getUri(), $request->user()->id,
            $request->attributes->get('campfire.session_id'),
            $request->header('Accept'), $request->header('Turbo-Frame'), $request->userAgent(),
            $room ? null : $request->cookie('last_room'), $room ? null : $request->session()->get('last_room_id'),
        ], JSON_THROW_ON_ERROR));
        $entry = $cache->get($key, $epoch);
        if ($entry !== null) {
            $response = response(implode(e($request->session()->token()), $entry['parts']), 200, $entry['headers']);
            if ($room !== null && ! str_ends_with($request->path(), '/messages')) {
                $request->session()->put('last_room_id', $room->id);
                $response->headers->setCookie(cookie('last_room', (string) $room->id, 60 * 24 * 365 * 20));
            }

            return $response;
        }
        // Capture only template-generated tokens; literal token text remains untouched.
        $token = $request->session()->token();
        $placeholder = 'csrf-'.bin2hex(random_bytes(16));
        $request->session()->put('_token', $placeholder);
        $request->attributes->set('campfire.capture_response', true);
        try {
            $response = $next($request);
        } finally {
            $request->session()->put('_token', $token);
            $request->attributes->remove('campfire.capture_response');
        }
        $body = $response->getContent();
        if ($response->getStatusCode() === 200 && is_string($body)
            && str_starts_with($response->headers->get('Content-Type', ''), 'text/html')) {
            $parts = explode($placeholder, $body);
            $headers = $response->headers->all();
            unset($headers['set-cookie'], $headers['date'], $headers['content-length']);
            $cache->put($key, $epoch, ['parts' => $parts, 'headers' => $headers]);
            $response->setContent(implode(e($token), $parts));
        } else {
            $response->setContent(str_replace($placeholder, e($token), $body === false ? '' : $body));
        }

        return $response;
    }
}
