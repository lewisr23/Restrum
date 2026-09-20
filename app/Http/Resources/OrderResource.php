<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as its buyer or seller is allowed to see it.
 *
 * No Stripe identifiers. They are internal plumbing, they are useful to
 * anyone trying to impersonate a webhook, and nothing in the interface needs
 * them: what a person wants to know is how much, what state, and what they
 * can do about it.
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerId = $request->user('sanctum')?->id;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,

            // The seller's side of the split, shown to both parties. A buyer
            // seeing the fee is a marketplace being open about what it takes,
            // and hiding it only makes the first seller payout a surprise.
            'platform_fee' => $this->platform_fee,
            'seller_proceeds' => $this->sellerProceeds(),

            'listing' => new ListingResource($this->whenLoaded('listing')),
            'buyer' => new UserSummaryResource($this->whenLoaded('buyer')),
            'seller' => new UserSummaryResource($this->whenLoaded('seller')),

            // Which side of this sale the person asking is on. The frontend
            // would otherwise have to compare ids it may not have loaded.
            'viewer_role' => match ($viewerId) {
                $this->buyer_id => 'BUYER',
                $this->seller_id => 'SELLER',
                default => null,
            },

            // The one action the interface has to offer at the right moment,
            // answered here so the rules about who may confirm live in one
            // place rather than being restated in React.
            'can_confirm' => $viewerId === $this->buyer_id
                && $this->status === OrderStatus::PAID,

            'reserved_until' => $this->reserved_until,
            'paid_at' => $this->paid_at,
            'dispatched_at' => $this->dispatched_at,
            'tracking_carrier' => $this->tracking_carrier,
            'tracking_number' => $this->tracking_number,
            'confirmed_at' => $this->confirmed_at,
            'released_at' => $this->released_at,
            'refunded_at' => $this->refunded_at,
            'created_at' => $this->created_at,
        ];
    }
}
