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
        /** Stripe will let this account take part in a payment. */
        public bool $chargesEnabled,
        /** Money can actually reach this account's bank. */
        public bool $payoutsEnabled,
        /** The seller finished the onboarding form, verified or not. */
        public bool $detailsSubmitted,
    ) {}

    /**
     * Whether this seller can be paid at all.
     *
     * Both flags, not either: an account that can be charged but not paid out
     * would take a buyer's money into a dead end.
     */
    public function canTrade(): bool
    {
        return $this->chargesEnabled && $this->payoutsEnabled;
    }
}
