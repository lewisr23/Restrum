<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:30', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'location' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = User::create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'location' => $data['location'] ?? null,
            'bio' => $data['bio'] ?? null,
        ]);

        // Called outright rather than left to Laravel's Registered event.
        // That listener is only wired up by an EventServiceProvider, which
        // this app does not have, so firing the event here would have sent
        // no mail at all and looked like it had. Firing it AND calling this
        // would send two the day somebody adds one.
        $user->sendEmailVerificationNotification();

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $user,
        ], 201);
    }

    /**
     * Accepts either username or email in the "login" field, matching how
     * people actually try to sign in - they rarely remember which one a
     * given site wants.
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $data['login'])
            ->orWhere('email', $data['login'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => 'Those credentials do not match an account.',
            ]);
        }

        // Checked after the password, deliberately. Answering "suspended"
        // to anyone who types the username would tell a stranger which
        // accounts have been actioned.
        if ($user->suspended_at !== null) {
            throw ValidationException::withMessages([
                'login' => 'This account is suspended. Email support if you think that is wrong.',
            ]);
        }

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
