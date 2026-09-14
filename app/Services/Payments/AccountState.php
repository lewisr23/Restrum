<?php

namespace App\Services\Payments;

/**
 * What Stripe currently believes about a seller's connected account.
 *
 * Deliberately three booleans rather than the whole account object. Almost
 * everything else Stripe returns is personal data the marketplace has no
 * business storing, and narrowing it here means no caller is tempted to.
 */
readonly class AccountState
{
    public function __construct(
        /** The platform may transfer money to this account. */
        public bool $transfersEnabled,
        /** Money can actually reach this account's bank. */
        public bool $payoutsEnabled,
        /** Stripe is not waiting on anything else from this seller. */
        public bool $detailsSubmitted,
    ) {}

    /**
     * Whether this seller can be paid at all.
     *
     * Both flags, not either: an account the platform can transfer to but
     * which cannot pay out to a bank is a dead end with the seller's money
     * sitting in it.
     */
    public function canTrade(): bool
    {
        return $this->transfersEnabled && $this->payoutsEnabled;
    }
}
