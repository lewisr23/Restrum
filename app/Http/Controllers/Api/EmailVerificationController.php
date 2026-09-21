<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Confirming that an address belongs to the person who typed it.
 *
 * What this buys, on a marketplace: an account is reachable when a sale
 * goes wrong, a suspension is worth something because coming back means
 * finding another mailbox, and a password reset has somewhere to land.
 * What it does not buy, and nobody should pretend otherwise, is an end to
 * throwaway accounts. Free mailboxes are free.
 */
class EmailVerificationController extends Controller
{
    /**
     * The target of the link in the email.
     *
     * Deliberately not behind auth:sanctum. The link is opened in a mail
     * client, which carries no bearer token, so requiring one would mean
     * verification only ever worked for people already logged in on that
     * device. The signature is what authenticates this request, and the
     * 'signed' middleware on the route has already checked it.
     *
     * Answers with a redirect rather than JSON because a person is reading
     * it, not a fetch() call.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        // Same answer for "no such user" and "hash does not match the
        // current address", because they are the same thing from outside:
        // this link is not good. hash_equals rather than === so that a
        // wrong hash cannot be found a character at a time.
        if (! $user || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return $this->back('invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->back('already');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return $this->back('verified');
    }

    /**
     * Send the link again.
     *
     * Behind auth, so it can only ever post to the address of the account
     * making the request. An unauthenticated resend that took an email
     * address would be a way to have Restrum mail a stranger on demand.
     */
    public function resend(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'That address is already confirmed.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Sent. Check your inbox, and the spam folder.']);
    }

    /**
     * Where the browser ends up. The React app reads ?status and says
     * something human; the API has no page of its own to show.
     */
    protected function back(string $status): RedirectResponse
    {
        return redirect()->away(
            rtrim((string) config('app.frontend_url'), '/')."/email-verified?status={$status}"
        );
    }
}
