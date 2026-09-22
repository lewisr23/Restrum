<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Fillable, but only ever set by MessageController from values it has
// already validated/decided itself, never straight from raw request input
// (see the Conversation model for why "no #[Fillable] at all" is NOT the
// safe default it looks like - it blocks legitimate server-side creation
// too, since Eloquent's default is an empty allow-list, not unguarded).
#[Fillable(['sender_id', 'content', 'message_type', 'offer_amount', 'offer_status', 'offer_expires_at', 'safety_flags'])]
class Message extends Model
{
    // Only created_at exists on this table - messages are immutable once sent.
    // UPDATED_AT = null is how Eloquent is told to manage a single timestamp
    // rather than the usual pair. The column has a DB-level useCurrent()
    // default too, but letting Eloquent write it is what keeps it on the same
    // clock as every other table: MySQL's NOW() is the server's local time,
    // while Laravel reads and serializes all stored timestamps as UTC. With
    // the DB filling this column, a message sent at 20:30 BST was stored as
    // 20:30 and then sent to the browser labelled UTC, so it rendered as
    // 21:30 - an hour in the future.
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'offer_amount' => 'decimal:2',
            'offer_expires_at' => 'datetime',
            'read_by_recipient' => 'boolean',
            'safety_flags' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Offers this buyer can still hold this listing's seller to.
     *
     * One definition, used by both the checkout that charges the agreed
     * price and the listing page that promises it. Two copies of this query
     * would be two chances for the button and the card reader to disagree,
     * which is the kind of disagreement a buyer takes personally.
     *
     * Scoped by the conversation as well as by who sent the message: only a
     * buyer can make an offer and a conversation's buyer never changes, so
     * the two conditions cannot disagree today. Both are here because this
     * decides what someone is charged, and the day they can disagree is not
     * the day to find out which one was load-bearing.
     *
     * Most recently accepted comes first when there is somehow more than
     * one. The alternative, cheapest first, would be the marketplace taking
     * the buyer's side in an argument about which agreement stands.
     */
    public function scopeClaimableBy(Builder $query, int $buyerId, int $listingId): Builder
    {
        return $query
            ->where('sender_id', $buyerId)
            ->where('message_type', 'PRICE_OFFER')
            ->where('offer_status', 'ACCEPTED')
            ->where('offer_expires_at', '>', now())
            ->whereHas('conversation', fn (Builder $conversation) => $conversation
                ->where('listing_id', $listingId)
                ->where('buyer_id', $buyerId))
            ->orderByDesc('offer_expires_at')
            ->orderByDesc('id');
    }

    /**
     * Whether this offer still entitles its buyer to the agreed price.
     *
     * Accepting an offer no longer sells anything (see the migration that
     * added offer_expires_at), so "accepted" on its own is not enough to
     * price a checkout: the window has to still be open. Expiry is decided
     * by reading this column rather than by a scheduled command, which means
     * there is no window in which an expired offer is still honoured because
     * a sweep has not run yet.
     */
    public function isClaimableOffer(): bool
    {
        return $this->message_type === 'PRICE_OFFER'
            && $this->offer_status === 'ACCEPTED'
            && $this->offer_expires_at !== null
            && $this->offer_expires_at->isFuture();
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
