<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\LimitMutations;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => ['web', 'auth', 'auth.session', EnsureActiveUser::class, 'throttle:60,1,broadcast-auth:', 'cache.headers:private;no_store;max_age=0'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectUsersTo(fn (Request $request) => route($request->user()->landingRoute()));
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_FOR);
        $middleware->prepend([TrustProxies::class, SecurityHeaders::class]);
        $middleware->web(append: [LimitMutations::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
