<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserProfileResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EndorsementController extends Controller
{
    /** Number of endorsements required before community_verified flips on. */
    private const VERIFICATION_THRESHOLD = 2;

    /**
     * Endorsing is a deliberate, one-way trust signal - not a quick toggle
     * like saving a listing. No un-endorse endpoint, matching the original
     * design: you can't quietly take back having vouched for someone.
     */
    public function store(Request $request, User $user)
    {
        $endorser = $request->user();

        if ($endorser->id === $user->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot endorse yourself.',
            ]);
        }

        if (! Conversation::betweenUsers($endorser->id, $user->id)->exists()) {
            throw ValidationException::withMessages([
                'user' => 'You can only endorse someone you have actually messaged.',
            ]);
        }

        if ($endorser->endorsementsGiven()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'user' => 'You have already endorsed this person.',
            ]);
        }

        $endorser->endorsementsGiven()->attach($user->id);

        if ($user->endorsementsReceived()->count() >= self::VERIFICATION_THRESHOLD) {
            $user->community_verified = true;
            $user->save();
        }

        return new UserProfileResource($user->fresh());
    }
}
