<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only routes.
 *
 * 404 rather than 403 on purpose: a 403 confirms the route exists, which
 * tells anyone poking around exactly where the moderation tooling lives.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_admin, 404);

        return $next($request);
    }
}
