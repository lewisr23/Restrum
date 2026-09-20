<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Getting back into an account.
 *
 * Until this existed a forgotten password was permanent: register, login and
 * logout were the only auth routes, so anyone locked out lost their order
 * history and their reputation with it. That also made banning pointless in
 * the other direction, since accounts were disposable rather than worth
 * keeping.
 *
 * Laravel's own broker does the work. The token table already existed,
 * unused, from the default schema.
 */
class PasswordResetController extends Controller
{
    /**
     * Ask for a reset link.
     *
     * Always answers the same way, whether or not the address is registered.
     * Saying "no account with that email" turns this endpoint into a way to
     * test whether someone is a member, which on a marketplace tells a
     * stranger who is worth targeting.
     */
    public function request(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        Password::sendResetLink($data);

        return response()->json([
            'message' => 'If that email has an account, a reset link is on its way.',
        ]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($data, function ($user, string $password) {
            $user->password = Hash::make($password);

            // A new remember token, so a session opened by whoever had the
            // old password stops working. Resetting a password you think
            // was stolen has to actually evict them.
            $user->setRememberToken(Str::random(60));
            $user->save();

            // Every issued API token goes too, for the same reason.
            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'That reset link is no longer valid. Ask for a new one.',
            ], 422);
        }

        return response()->json(['message' => 'Password changed. You can log in now.']);
    }
}
