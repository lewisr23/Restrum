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

            // Feedback from people who actually bought or sold, as opposed
            // to endorsements, which only require a conversation and can
            // therefore be manufactured by two accounts talking to each
            // other. Both are shown; only this one costs anything to earn.
            'rating_average' => $this->reviewsReceived()->count() > 0
                ? round((float) $this->reviewsReceived()->avg('rating'), 1)
                : null,
            'rating_count' => $this->reviewsReceived()->count(),
            'reviews' => $this->reviewsReceived()
                ->with('reviewer:id,username')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn ($review) => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'reviewer_role' => $review->reviewer_role,
                    'reviewer' => $review->reviewer?->username,
                    'created_at' => $review->created_at,
                ]),
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
            // Sold gear belongs here and nowhere else. In search it is a row
            // a buyer has to read and reject; on a profile it is the only
            // evidence a stranger has that this person has actually sold
            // something before, which is exactly what they are looking for
            // when they click through to a seller they do not know.
            //
            // Capped rather than unbounded: a prolific seller would
            // otherwise make their own profile slow to load, and nobody
            // scrolls to the fortieth sale.
            'sold_listings' => ListingResource::collection(
                $this->listings()
                    ->with('seller', 'media')
                    ->where('status', 'SOLD')
                    ->latest()
                    ->limit(12)
                    ->get()
            ),
            'sold_count' => $this->listings()->where('status', 'SOLD')->count(),
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
