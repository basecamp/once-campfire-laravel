<?php

use App\Http\Middleware\AuthenticateCampfire;
use App\Http\Middleware\CacheResponses;
use App\Http\Middleware\FetchMetadata;
use App\Http\Middleware\RailsSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['session_token', '_campfire_session']);
        $middleware->web(remove: [PreventRequestForgery::class], append: [FetchMetadata::class, RailsSession::class]);
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: explode(',', $proxies));
        }
        $middleware->alias(['campfire.auth' => AuthenticateCampfire::class, 'campfire.cache' => CacheResponses::class]);
    })
    ->withExceptions()
    ->create();
