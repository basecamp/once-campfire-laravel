<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RailsCrypto;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuthenticateCampfire
{
    public function handle(Request $request, Closure $next)
    {
        // Rails only rejects banned addresses on unsafe requests (BlockBannedRequests#safe_request?).
        if (! $request->isMethodSafe() && DB::table('bans')->where('ip_address', $request->ip())->exists()) {
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
        // Read on every request: logout, ban and deactivation revoke by writing SQLite, possibly from another process.
        $row = is_string($token)
            ? DB::selectOne('SELECT s.id AS campfire_session_id, s.last_active_at AS campfire_last_active_at, u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ? AND u.status = 0 LIMIT 1', [$token])
            : null;
        if (! $row) {
            $request->session()->put('return_to', $request->getRequestUri());

            return redirect('/session/new');
        }
        $row = (array) $row;
        $sessionId = $row['campfire_session_id'];
        $lastActive = $row['campfire_last_active_at'];
        unset($row['campfire_session_id'], $row['campfire_last_active_at']);
        $user = (new User)->newFromBuilder($row);
        if ($user->role === 2) {
            abort(403);
        }
        $request->attributes->set('campfire_user', $user);
        $request->attributes->set('campfire_session_id', $sessionId);
        view()->share('currentUser', $user);
        $request->setUserResolver(fn () => $user);
        if (strtotime((string) $lastActive) < time() - 3600) {
            $now = now();
            DB::table('sessions')->where('id', $sessionId)->update(['last_active_at' => $now, 'updated_at' => $now, 'user_agent' => $request->userAgent(), 'ip_address' => $request->ip()]);
        }

        return $next($request);
    }
}
