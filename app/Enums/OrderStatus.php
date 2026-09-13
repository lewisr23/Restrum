<?php

namespace App\Enums;

/**
 * The lifecycle of a sale.
 *
 * A backed enum rather than the string columns used elsewhere (Listing's
 * status, condition and category) because this one is a state machine rather
 * than a label: the legal transitions are as much a part of the domain as the
 * values, and keeping them next to the cases is what stops that knowledge
 * being scattered across controllers and webhook handlers.
 *
 * Money is held by the platform between PAID and RELEASED. That gap is the
 * whole point of the design: it is what lets a buyer be made whole when an
 * item never arrives, and it is why the site can claim to protect a purchase
 * rather than asking a stranger to be trustworthy.
 */
enum OrderStatus: string
{
    /** Order created, buyer has not completed Stripe Checkout yet. */
    case PENDING = 'PENDING';

    /** Payment captured. Funds sit with the platform, not the seller. */
    case PAID = 'PAID';

    /** Buyer confirmed the item arrived as described. Release is due. */
    case CONFIRMED = 'CONFIRMED';

    /** Funds transferred to the seller's connected account. Terminal. */
    case RELEASED = 'RELEASED';

    /** Buyer refunded. Terminal. */
    case REFUNDED = 'REFUNDED';

    /** Abandoned before payment, or the listing went away first. Terminal. */
    case CANCELLED = 'CANCELLED';

    /** Buyer raised a problem, or Stripe reported a chargeback. */
    case DISPUTED = 'DISPUTED';

    /**
     * Transitions that are allowed to happen, as a whitelist.
     *
     * Everything not listed here is a bug rather than an edge case, which
     * matters most for webhooks: Stripe redelivers events, and events can
     * arrive out of order, so "payment succeeded" can land on an order that
     * has already moved on. Rejecting the transition is the correct response
     * to that, not re-applying it.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::PENDING => [self::PAID, self::CANCELLED],
            // A refund straight from PAID covers the seller cancelling, or an
            // item that never shipped, without the buyer having to confirm
            // receipt of something they never got.
            self::PAID => [self::CONFIRMED, self::REFUNDED, self::DISPUTED],
            self::CONFIRMED => [self::RELEASED, self::DISPUTED],
            // A chargeback can still land after the seller has been paid, in
            // which case the platform is out of pocket and recovery is a
            // manual matter. Modelling it is better than pretending it cannot
            // happen.
            self::RELEASED => [self::DISPUTED],
            self::DISPUTED => [self::REFUNDED, self::RELEASED],
            self::REFUNDED, self::CANCELLED => [],
        };
    }

    /** Terminal states hold no money and need no further action. */
    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /**
     * Whether the platform is currently holding this buyer's money.
     *
     * Used to decide whether a listing is genuinely unavailable: an order
     * sitting in PENDING has taken no money and must not keep an instrument
     * off the market indefinitely.
     */
    public function holdsFunds(): bool
    {
        return in_array($this, [self::PAID, self::CONFIRMED, self::DISPUTED], true);
    }
}
