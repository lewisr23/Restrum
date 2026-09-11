<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Public route, no auth:sanctum middleware - same reasoning as
        // ListingResource's saved_by_viewer: plain user() would silently
        // always be null here regardless of a valid Bearer token.
        $viewer = $request->user('sanctum');

        return [
            'id' => $this->id,
            'username' => $this->username,
            'location' => $this->location,
            'bio' => $this->bio,
            'community_verified' => $this->community_verified,
            'member_since' => $this->created_at,
            'endorsement_count' => $this->endorsementsReceived()->count(),
            'follower_count' => $this->followers()->count(),
            'following_count' => $this->following()->count(),
            // with('seller', 'media') - same reason as
            // ListingController::index()/saved(): without eager-loading,
            // ListingResource's whenLoaded('media') silently omits the key
            // entirely, so every card on a profile page would show the
            // placeholder icon even for listings that have real photos.
            'listings' => ListingResource::collection(
                $this->listings()->with('seller', 'media')->where('status', 'ACTIVE')->latest()->get()
            ),
            'viewer_context' => $this->when($viewer && $viewer->id !== $this->id, fn () => [
                'am_i_following' => $viewer->following()->where('users.id', $this->id)->exists(),
                'have_i_endorsed' => $viewer->endorsementsGiven()->where('users.id', $this->id)->exists(),
                // Mirrors EndorsementController::store's own gate - shown so
                // the frontend can grey out the button with a reason instead
                // of just letting the request fail.
                'can_endorse' => Conversation::betweenUsers($viewer->id, $this->id)->exists(),
            ]),
        ];
    }
}
