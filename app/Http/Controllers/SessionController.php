<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\RailsCrypto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class SessionController extends Controller
{
    public function new()
    {
        return User::exists() ? view('sessions.new') : redirect('/first_run');
    }

    public function create(Request $r)
    {
        $key = 'login:'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->view('sessions.new', ['error' => true], 429);
        }
        RateLimiter::hit($key, 180);
        $user = User::active()->where('email_address', trim($r->input('email_address', '')))->first();
        if (! $user || ! $user->password_digest || ! password_verify($r->input('password', ''), $user->password_digest)) {
            return response()->view('sessions.new', ['error' => true], 401);
        }
        $r->session()->regenerate(true);

        return $this->start($r, $user);
    }

    public function start(Request $r, User $user)
    {
        $token = bin2hex(random_bytes(18));
        DB::table('sessions')->insert(['user_id' => $user->id, 'token' => $token, 'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'ip_address' => $r->ip(), 'user_agent' => $r->userAgent()]);

        return redirect($r->session()->pull('return_to', '/'))->withCookie(cookie('session_token', app(RailsCrypto::class)->signCookie('session_token', $token, now()->addYears(20)->format('Y-m-d\\TH:i:s.v\\Z')), 60 * 24 * 365 * 20, '/', null, $r->isSecure(), true, false, 'lax'));
    }

    public function destroy(Request $r)
    {
        $token = app(RailsCrypto::class)->verifyCookie('session_token', $r->cookie('session_token'));
        if (is_string($token)) {
            DB::table('sessions')->where('token', $token)->delete();
        }
        $r->session()->invalidate();

        return redirect('/')->withoutCookie('session_token');
    }
}
