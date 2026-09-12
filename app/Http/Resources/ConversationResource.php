<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Every route this resource is used from sits behind auth:sanctum,
        // so plain user() (not the explicit 'sanctum' guard ListingResource
        // needs) is safe here - the middleware has already resolved it.
        $viewer = $request->user();
        $isBuyer = $viewer->id === $this->buyer_id;
        $otherParticipant = $isBuyer ? $this->seller : $this->buyer;

        return [
            'id' => $this->id,
            'listing' => [
                'id' => $this->listing->id,
                'title' => $this->listing->title,
                'price' => $this->listing->price,
                'status' => $this->listing->status,
            ],
            // The OTHER person in the thread, from this viewer's side -
            // the frontend inbox shows "who you're talking to", not both.
            'other_participant' => new UserSummaryResource($otherParticipant),
            'am_i_seller' => ! $isBuyer,
            'last_message_at' => $this->last_message_at,
            // Truncated preview for the inbox row - null for a conversation
            // that was somehow created with no messages yet.
            'latest_message_preview' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage
                ? str($this->latestMessage->content)->limit(80)->toString()
                : null),
            // Mirrors the check in EndorsementController::store - whether the
            // viewer has already vouched for the other participant, so the
            // inbox/chat UI can hide an already-used endorse action.
            //
            // Read through the relation rather than as ->endorsementsGiven()
            // ->where(...)->exists(). The query builder form runs a query for
            // every row in the inbox; the relation loads once on the viewer
            // model and every later row reads the cached collection. A person
            // endorses a handful of people at most, so holding them in memory
            // costs nothing.
            'has_endorsed_other' => $viewer->endorsementsGiven->contains($otherParticipant),
            // Set by the controller via withCount(['messages as unread_count'
            // => ...]) - the condition depends on who's viewing, which a
            // static model relation can't parameterize, so it's built as a
            // query-time closure there rather than here.
            'unread_count' => $this->unread_count ?? 0,
        ];
    }
}
