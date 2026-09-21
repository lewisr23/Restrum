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
        /*
         * In production every request arrives from nginx, which arrives from
         * Cloudflare, so REMOTE_ADDR is a container address and the visitor's
         * own is only in a forwarded header. Left untrusted, two things break
         * quietly rather than loudly: the gear adviser's per-IP rate limit
         * collapses into one bucket shared by the entire internet, and every
         * generated URL comes out http:// on a site only served over https.
         *
         * Trusting every proxy is safe here because of how the header
         * arrives, not because the range is narrow. PHP-FPM's port is never
         * published outside the compose network, so nginx is the only thing
         * that can reach it, and docker/nginx.prod.conf SETS
         * X-Forwarded-For from the address Cloudflare vouched for rather
         * than appending to whatever the client sent. A forged header is
         * discarded a hop before it gets here. Pinning an address instead
         * would only pin Docker's choice of container IP, which changes.
         *
         * Locally this is inert: no proxy sends these headers, so ip() and
         * isSecure() answer from the connection as before.
         */
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,

            // Deliberately shadows Laravel's own 'verified' alias. The
            // stock one redirects to a named web route this API does not
            // have, and knows nothing about the rollout switch in
            // config/features.php.
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
