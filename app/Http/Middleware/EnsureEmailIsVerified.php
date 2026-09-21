<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the actions that carry money or reach another member until the
 * account's address has been confirmed.
 *
 * Laravel ships a 'verified' middleware already; this is not it. The stock
 * one redirects to a named web route and has no idea that enforcement here
 * is behind a switch. See config/features.php for why the switch exists:
 * turning this on before mail actually sends would lock out every new
 * account permanently.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('features.require_email_verification')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user && ! $user->hasVerifiedEmail()) {
            // 409 rather than 403. The request is not forbidden, it is
            // premature, and the frontend needs to tell the two apart to
            // know whether to offer the "resend" button or an apology.
            return response()->json([
                'message' => 'Confirm your email address before you can do this. Check your inbox for the link, or ask for a new one.',
                'reason' => 'email_unverified',
            ], 409);
        }

        return $next($request);
    }
}
