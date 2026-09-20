<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// community_verified is deliberately NOT fillable - it is derived by
// EndorsementService from user_endorsements, never set directly by request input.
#[Fillable(['username', 'email', 'password', 'location', 'bio'])]
// stripe_account_id is hidden rather than merely unused by the resources: it
// identifies a real Stripe account, and a marketplace has no reason to put one
// seller's account id in front of another user.
// suspension_reason is hidden because it is an internal note, not a
// public label, and is_admin because who moderates is not everyone's
// business.
#[Hidden(['password', 'remember_token', 'stripe_account_id', 'suspension_reason', 'is_admin'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'community_verified' => 'boolean',
            'is_admin' => 'boolean',
            'suspended_at' => 'datetime',
            'stripe_transfers_enabled' => 'boolean',
            'stripe_payouts_enabled' => 'boolean',
            'stripe_synced_at' => 'datetime',
        ];
    }

    /**
     * Whether this seller can actually be paid for a sale.
     *
     * All three conditions, not just the account existing. Stripe creates an
     * account the moment onboarding starts, long before it will let money
     * move, so an account id on its own says only that somebody began filling
     * in a form. Checking it alone is how a marketplace ends up taking a
     * buyer's money for an instrument whose seller can never receive it.
     */
    /** Feedback written about this user. */
    public function reviewsReceived()
    {
        return $this->hasMany(\App\Models\Review::class, 'subject_id');
    }

    public function canReceivePayments(): bool
    {
        return $this->stripe_account_id !== null
            && $this->stripe_transfers_enabled
            && $this->stripe_payouts_enabled;
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'seller_id');
    }

    public function conversationsAsBuyer(): HasMany
    {
        return $this->hasMany(Conversation::class, 'buyer_id');
    }

    public function conversationsAsSeller(): HasMany
    {
        return $this->hasMany(Conversation::class, 'seller_id');
    }

    /** Orders this user is buying. */
    public function purchases(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    /** Orders this user is selling. */
    public function sales(): HasMany
    {
        return $this->hasMany(Order::class, 'seller_id');
    }

    public function messagesSent(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function savedListings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'saved_listings')
            ->withTimestamps();
    }

    /** Users this user has endorsed. */
    public function endorsementsGiven(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_endorsements', 'endorser_id', 'endorsed_id')
            ->withTimestamps();
    }

    /** Users who have endorsed this user - what community_verified is derived from. */
    public function endorsementsReceived(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_endorsements', 'endorsed_id', 'endorser_id')
            ->withTimestamps();
    }

    /** Sellers this user follows. */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_follows', 'follower_id', 'followed_id')
            ->withTimestamps();
    }

    /** Users following this user. */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_follows', 'followed_id', 'follower_id')
            ->withTimestamps();
    }
}
