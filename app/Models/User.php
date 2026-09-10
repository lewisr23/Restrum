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
#[Hidden(['password', 'remember_token'])]
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
        ];
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
