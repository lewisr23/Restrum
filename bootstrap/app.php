<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Deliberately NOT passing channels: to withRouting() above.
    // withRouting(channels: ...) always registers /broadcasting/auth under
    // the 'web' (session-cookie) middleware group with no way to override
    // it - fine for a session-based SPA, useless for this Bearer-token API,
    // where every real request already goes through auth:sanctum instead.
    // withBroadcasting() is the same underlying call with attributes exposed,
    // so it's used directly here to get the right guard on the auth route.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
