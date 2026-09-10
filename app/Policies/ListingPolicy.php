<?php

namespace App\Policies;

use App\Models\Listing;
use App\Models\User;

class ListingPolicy
{
    /**
     * Only the seller who owns a listing can edit it. Everything else
     * (browsing, viewing, creating) is governed by route middleware, not
     * this policy - there is nothing listing-specific to check for those.
     */
    public function update(User $user, Listing $listing): bool
    {
        return $user->id === $listing->seller_id;
    }
}
