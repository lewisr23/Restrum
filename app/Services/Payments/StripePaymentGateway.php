<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\User;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * The real gateway: Stripe Connect, separate charges and transfers.
 *
 * Every method here is a network call, so nothing in this class is called
 * from inside a database transaction. Holding a row lock across a call to
 * Stripe would mean a listing stays locked for as long as Stripe is slow,
 * and Stripe being slow is not hypothetical.
 */
class StripePaymentGateway implements PaymentGateway
{
    private ?StripeClient $stripe = null;

    public function __construct(
        private readonly ?string $secret,
        private readonly ?string $webhookSecret,
    ) {}

    public function createConnectedAccount(User $seller): string
    {
        return $this->call(fn () => $this->client()->accounts->create([
            // Express, not Standard: Stripe hosts the onboarding form and the
            // payouts dashboard, which is the difference between asking a
            // private seller for their bank details and sending them to
            // Stripe to hand them over. Custom would mean owning that
            // liability, and the compliance work that comes with it.
            'type' => 'express',
            'country' => 'GB',
            'email' => $seller->email,
            'business_type' => 'individual',

            // Only transfers. card_payments is what a destination charge
            // would need, and requesting capabilities the integration does
            // not use means asking sellers for information it does not need.
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
            'business_profile' => [
                'product_description' => 'Sells secondhand musical instruments and audio equipment on Restrum.',
            ],

            // So an account found in the Stripe dashboard can be traced back
            // to a user without a database lookup, which is exactly the
            // position support is in when something has gone wrong.
            'metadata' => ['user_id' => (string) $seller->id],
        ])->id);
    }

    public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->call(fn () => $this->client()->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ])->url);
    }

    public function fetchAccountState(string $accountId): AccountState
    {
        return $this->call(function () use ($accountId) {
            $account = $this->client()->accounts->retrieve($accountId);

            return new AccountState(
                chargesEnabled: (bool) $account->charges_enabled,
                payoutsEnabled: (bool) $account->payouts_enabled,
                detailsSubmitted: (bool) $account->details_submitted,
            );
        });
    }

    public function openCheckout(Order $order, string $successUrl, string $cancelUrl): CheckoutHandle
    {
        $listing = $order->listing;

        return $this->call(function () use ($order, $listing, $successUrl, $cancelUrl) {
            $session = $this->client()->checkout->sessions->create([
                'mode' => 'payment',

                // Shows against the payment in the Stripe dashboard, which is
                // the first place anyone looks when a buyer writes in.
                'client_reference_id' => (string) $order->id,

                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,

                // No expires_at, deliberately. Stripe only allows a session to
                // live between 30 minutes and 24 hours, but the reservation
                // window is marketplace policy and may be set shorter than
                // that, so tying the two together would make a configurable
                // value silently invalid. The reservation is the source of
                // truth, and the sweeper expires the session when it lapses.

                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($order->currency),
                        // Stripe works in minor units. The order's own
                        // conversion is used rather than a second one here,
                        // so the amount charged and the amount recorded
                        // cannot drift apart.
                        'unit_amount' => $order->amountInPence(),
                        'product_data' => [
                            'name' => $listing->title,
                            'description' => $this->describe($listing->description),
                        ],
                    ],
                ]],

                'payment_intent_data' => [
                    // Ties the charge and the later transfer together, so a
                    // seller's payout can be traced to the buyer's payment in
                    // Stripe's own reporting and not only in this database.
                    'transfer_group' => $order->transferGroup(),
                    'metadata' => ['order_id' => (string) $order->id],
                ],

                // What the webhook reads. client_reference_id is not enough on
                // its own, since it is absent from some of the event shapes
                // this integration has to handle.
                'metadata' => ['order_id' => (string) $order->id],
            ], [
                // One session per order, however many times a buyer retries a
                // request that failed halfway through.
                'idempotency_key' => "checkout_order_{$order->id}",
            ]);

            return new CheckoutHandle(
                sessionId: $session->id,
                url: $session->url,
            );
        });
    }

    public function abandonCheckout(string $sessionId): bool
    {
        try {
            $this->client()->checkout->sessions->expire($sessionId);

            return true;
        } catch (InvalidRequestException) {
            // Stripe refuses to expire a session that has already reached a
            // final state, and the two final states mean opposite things to
            // the caller, so the reason has to be established rather than
            // assumed. Asking afterwards rather than before is deliberate:
            // checking first would leave a window in which the buyer pays
            // between the check and the expiry.
            try {
                $session = $this->client()->checkout->sessions->retrieve($sessionId);
            } catch (ApiErrorException) {
                // Stripe cannot even find it. Nothing here can take money.
                return true;
            }

            return $session->status !== 'complete' && $session->payment_status !== 'paid';
        } catch (ApiErrorException $e) {
            throw new PaymentGatewayException($e->getMessage(), previous: $e);
        }
    }

    public function payOutSeller(Order $order, string $idempotencyKey): string
    {
        $destination = $order->seller->stripe_account_id;

        if ($destination === null) {
            throw new PaymentGatewayException(
                "Order {$order->id} cannot pay out: seller {$order->seller_id} has no connected account."
            );
        }

        return $this->call(fn () => $this->client()->transfers->create([
            'amount' => $order->sellerProceedsInPence(),
            'currency' => strtolower($order->currency),
            'destination' => $destination,
            'transfer_group' => $order->transferGroup(),
            'metadata' => ['order_id' => (string) $order->id],

            // No source_transaction. It would tie this transfer to the
            // original charge and let it through before the funds settle, but
            // escrow means days pass between the payment and this call, by
            // which time the money is long since available.
        ], [
            'idempotency_key' => $idempotencyKey,
        ])->id);
    }

    public function refundBuyer(Order $order, string $idempotencyKey): string
    {
        if ($order->stripe_payment_intent_id === null) {
            throw new PaymentGatewayException(
                "Order {$order->id} cannot be refunded: no payment was recorded against it."
            );
        }

        return $this->call(fn () => $this->client()->refunds->create([
            'payment_intent' => $order->stripe_payment_intent_id,
            'metadata' => ['order_id' => (string) $order->id],
        ], [
            'idempotency_key' => $idempotencyKey,
        ])->id);
    }

    public function parseWebhook(string $payload, string $signature): array
    {
        if (($this->webhookSecret ?? '') === '') {
            // Refusing here rather than skipping verification is the point of
            // the check: an unconfigured secret has to close the endpoint, not
            // open it. A deployment that accepts unsigned webhooks is a
            // stranger being able to mark orders paid.
            throw new PaymentGatewayException('Stripe webhook secret is not configured.');
        }

        try {
            return Webhook::constructEvent($payload, $signature, $this->webhookSecret)->toArray();
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new PaymentGatewayException('Stripe webhook signature is invalid.', previous: $e);
        }
    }

    /**
     * Stripe shows this to the buyer on the checkout page and rejects it past
     * a certain length, so a seller's long description is trimmed here rather
     * than being allowed to fail the payment.
     */
    private function describe(?string $description): string
    {
        $text = trim((string) $description);

        if ($text === '') {
            return 'Secondhand instrument listed on Restrum.';
        }

        return mb_strimwidth($text, 0, 250, '...');
    }

    private function client(): StripeClient
    {
        if (($this->secret ?? '') === '') {
            throw new PaymentGatewayException('Stripe secret key is not configured.');
        }

        return $this->stripe ??= new StripeClient($this->secret);
    }

    /**
     * Runs a Stripe call and translates its failures into one exception type.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function call(callable $call)
    {
        try {
            return $call();
        } catch (ApiErrorException $e) {
            throw new PaymentGatewayException($e->getMessage(), previous: $e);
        }
    }
}
