<?php

namespace App\Services\Payments;

/**
 * The two things the app needs back from an opened PaymentIntent: what the
 * browser mounts the payment form against, and what to recognise when the
 * webhook arrives.
 *
 * The client secret authorises one browser to confirm one payment. It is not
 * a credential for the Stripe account, but it is a live way to pay for an
 * instrument, so it goes to the buyer who reserved the listing and nowhere
 * else.
 */
readonly class PaymentHandle
{
    public function __construct(
        public string $paymentIntentId,
        public string $clientSecret,
    ) {}
}
