<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FollowController extends Controller
{
    /** Toggles the current user's following state for another user. */
    public function toggle(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot follow yourself.',
            ]);
        }

        $result = $request->user()->following()->toggle($user->id);

        return response()->json([
            'following' => count($result['attached']) > 0,
        ]);
    }
}
