<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\User;

/**
 * Everything the marketplace asks of a payment processor.
 *
 * An interface rather than calling Stripe directly from the controllers for
 * one reason above all: the tests. The money paths - reserving a listing,
 * recording a payment, releasing escrow to a seller - are the parts of this
 * codebase most worth testing and the least acceptable to test against a
 * live API, and a seam here is what lets the whole flow run in memory.
 *
 * It is not an abstraction over "any payment provider". The method names
 * describe what the marketplace needs done, so a second implementation would
 * be judged on whether it can do these things, not on whether it resembles
 * Stripe.
 */
interface PaymentGateway
{
    /**
     * Create the connected account a seller will be paid into.
     *
     * Returns Stripe's acct_... identifier, which the caller is responsible
     * for storing: an account created and then not recorded is orphaned, and
     * the seller will be asked to onboard again.
     *
     * @throws PaymentGatewayException
     */
    public function createConnectedAccount(User $seller): string;

    /**
     * A client secret that lets Stripe's embedded components act for one
     * connected account inside our own page.
     *
     * This replaced a redirect to Stripe's hosted onboarding. The form is
     * still Stripe's, in a cross-origin iframe, so identity and bank details
     * still go straight to Stripe; the seller just never leaves Restrum to
     * fill it in. Short-lived by Stripe's design, so it is generated on
     * demand and never stored.
     *
     * @throws PaymentGatewayException
     */
    public function createOnboardingSession(string $accountId): string;

    /**
     * Ask Stripe what it currently thinks of an account.
     *
     * Used to reconcile after onboarding, when the seller is back on the site
     * but the account.updated webhook may not have landed yet.
     *
     * @throws PaymentGatewayException
     */
    public function fetchAccountState(string $accountId): AccountState;

    /**
     * Open a payment for an order that is already reserved.
     *
     * No URLs, unlike the hosted Checkout this replaced. The payment form is
     * mounted on our own checkout page, so where the buyer goes afterwards
     * is the browser's business and is passed to Stripe.js at confirmation
     * time rather than being fixed here.
     *
     * The charge is taken by the PLATFORM, not the seller: this is Stripe's
     * separate charges and transfers model, and it is the only one that lets
     * the money sit still between payment and delivery. A destination charge
     * would pay the seller the moment the card clears, which is precisely the
     * thing buyer protection cannot allow.
     *
     * @throws PaymentGatewayException
     */
    public function openPayment(Order $order): PaymentHandle;

    /**
     * Cancel a payment the buyer never completed.
     *
     * Returns false, and only false, when the payment could not be cancelled
     * because the money is or may yet be the platform's. That distinction is
     * load bearing: the caller is a sweeper about to cancel the order, and
     * cancelling an order Stripe has taken money for would strand a real
     * payment in a terminal state no webhook can rescue it from.
     *
     * So a payment that has succeeded returns false, and so does one still
     * settling, because "not yet" is not the same as "no" and guessing wrong
     * costs a buyer their money. Every other outcome - cancelled now,
     * cancelled already, unknown to Stripe - returns true, because all of
     * them mean nobody can pay against this any more.
     */
    public function abandonPayment(string $paymentIntentId): bool;

    /**
     * Send the seller their share out of the platform balance.
     *
     * $idempotencyKey is not optional politeness. This is the call that moves
     * real money out, and it can be retried by a queue worker that died after
     * Stripe succeeded but before the database was updated; without a key
     * that retry pays the seller twice.
     *
     * @throws PaymentGatewayException
     */
    public function payOutSeller(Order $order, string $idempotencyKey): string;

    /**
     * Refund the buyer in full.
     *
     * Only ever called for orders whose money the platform still holds, so
     * there is no seller transfer to claw back first.
     *
     * @throws PaymentGatewayException
     */
    public function refundBuyer(Order $order, string $idempotencyKey): string;

    /**
     * Verify a webhook's signature and return its decoded payload.
     *
     * The verification is the point. Without it this endpoint is an
     * unauthenticated way to tell the marketplace that money arrived.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException on a bad or missing signature
     */
    public function parseWebhook(string $payload, string $signature): array;
}
