<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// listing_id/buyer_id/seller_id are fillable, but never from raw request
// body - MessageController::startOrContinue sets them from the route-bound
// Listing and the authenticated user, not from client input. (An earlier
// version of this class had no #[Fillable] at all, on the theory that
// "nothing fillable" meant "protected". It doesn't - Eloquent's default
// with no fillable declared is an EMPTY allow-list, not an unguarded model,
// so firstOrNew() below couldn't set anything at all and threw
// MassAssignmentException. Found by actually running the create flow.)
#[Fillable(['listing_id', 'buyer_id', 'seller_id'])]
class Conversation extends Model
{
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Whether two users have ever shared a conversation, in either
     * buyer/seller direction. Backs the "you must have actually messaged
     * this person" gate on endorsements - a single source of truth so the
     * enforcement in EndorsementController and the preview shown by
     * UserProfileResource can't drift apart.
     */
    public function scopeBetweenUsers($query, int $userIdA, int $userIdB)
    {
        return $query->where(function ($q) use ($userIdA, $userIdB) {
            $q->where('buyer_id', $userIdA)->where('seller_id', $userIdB);
        })->orWhere(function ($q) use ($userIdA, $userIdB) {
            $q->where('buyer_id', $userIdB)->where('seller_id', $userIdA);
        });
    }
}
