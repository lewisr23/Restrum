<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean seller summary embedded in listing responses - deliberately excludes
 * email/bio so browsing a listing never leaks more about the seller than a
 * storefront should. The full profile lives behind its own endpoint.
 */
class UserSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'location' => $this->location,
            'community_verified' => $this->community_verified,
        ];
    }
}
