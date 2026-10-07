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
        if (CacheResponses::eligible($request)) {
            $request->attributes->set('campfire.response_epoch', app(ResponseCache::class)->epoch());
        }
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
        $session = is_string($token) ? DB::table('sessions')->where('token', $token)->first() : null;
        $user = $session ? User::active()->find($session->user_id) : null;
        if (! $user) {
            $request->session()->put('return_to', $request->getRequestUri());

            return redirect('/session/new');
        }
        if ($user->role === 2) {
            abort(403);
        }
        $request->attributes->set('campfire_user', $user);
        $request->attributes->set('campfire.session_id', $session->id);
        view()->share('currentUser', $user);
        $request->setUserResolver(fn () => $user);
        if (strtotime($session->last_active_at) < time() - 3600) {
            DB::table('sessions')->where('id', $session->id)->update(['last_active_at' => now(), 'updated_at' => now(), 'user_agent' => $request->userAgent(), 'ip_address' => $request->ip()]);
        }

        return $next($request);
    }
}
