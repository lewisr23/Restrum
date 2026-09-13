<?php

namespace App\Services\Payments;

/**
 * The two things the app needs back from a created Stripe Checkout Session:
 * where to send the buyer, and what to recognise when the webhook arrives.
 */
readonly class CheckoutHandle
{
    public function __construct(
        public string $sessionId,
        public string $url,
    ) {}
}
