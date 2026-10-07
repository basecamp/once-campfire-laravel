<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RailsCrypto;
use App\Support\ResponseCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuthenticateCampfire
{
    public function handle(Request $request, Closure $next)
    {
        // Native fragment renders need the same pre-authentication snapshot as page hits.
        $request->attributes->set('campfire.response_epoch', app(ResponseCache::class)->epoch());
        if (DB::table('bans')->where('ip_address', $request->ip())->exists()) {
            abort(403);
        }
        $key = $request->input('bot_key');
        if (is_string($key)) {
            $parts = explode('-', trim($key), 2);
            if (count($parts) === 2 && User::active()->where('role', 2)->where('id', $parts[0])->where('bot_token', $parts[1])->exists()) {
                abort(403);
            }
        }
        $crypto = app(RailsCrypto::class);
        $token = $crypto->verifyCookie('session_token', $request->cookie('session_token'));
        // Always read the current rows: another SQLite writer can revoke either record.
        $row = is_string($token)
            ? DB::selectOne('SELECT s.id AS campfire_session_id, s.last_active_at AS campfire_last_active_at, u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ? AND u.status = 0 LIMIT 1', [$token])
            : null;
        if (! $row) {
            $request->session()->put('return_to', $request->getRequestUri());

            return redirect('/session/new');
        }
        $attributes = (array) $row;
        $sessionId = $attributes['campfire_session_id'];
        $lastActive = $attributes['campfire_last_active_at'];
        unset($attributes['campfire_session_id'], $attributes['campfire_last_active_at']);
        $user = (new User)->newFromBuilder($attributes);
        if ($user->role === 2) {
            abort(403);
        }
        $request->attributes->set('campfire_user', $user);
        $request->attributes->set('campfire.session_id', $sessionId);
        view()->share('currentUser', $user);
        $request->setUserResolver(fn () => $user);
        if (strtotime($lastActive) < time() - 3600) {
            DB::table('sessions')->where('id', $sessionId)->update(['last_active_at' => now(), 'updated_at' => now(), 'user_agent' => $request->userAgent(), 'ip_address' => $request->ip()]);
        }

        return $next($request);
    }
}
