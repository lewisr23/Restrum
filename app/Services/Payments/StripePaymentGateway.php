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
        return $this->call(fn () => $this->client()->v2->core->accounts->create([
            'contact_email' => $seller->email,
            'display_name' => $seller->username,

            // Express: Stripe hosts the onboarding form and the payouts
            // dashboard, which is the difference between asking a private
            // seller for their bank details and sending them to Stripe to
            // hand them over. 'full' would drop them into the real Stripe
            // dashboard; 'none' would mean building one ourselves.
            'dashboard' => 'express',

            'identity' => [
                'country' => 'GB',
                'entity_type' => 'individual',
            ],

            // recipient, and only recipient. A seller here RECEIVES money
            // out of the platform balance; they never take a card payment
            // themselves, because the platform takes it and holds it. Asking
            // for the merchant configuration as well would mean putting
            // sellers through checks for a capability this marketplace never
            // uses.
            'configuration' => [
                'recipient' => [
                    'capabilities' => [
                        'stripe_balance' => [
                            'stripe_transfers' => ['requested' => true],
                        ],
                    ],
                ],
            ],

            'defaults' => [
                'currency' => 'gbp',

                // The platform collects the fees and carries the losses.
                // Not really a choice: the charge is on the platform under
                // separate charges and transfers, so a chargeback lands here
                // whatever this field said.
                'responsibilities' => [
                    'fees_collector' => 'application',
                    'losses_collector' => 'application',
                ],
            ],

            // So an account found in the Stripe dashboard can be traced back
            // to a user without a database lookup, which is exactly the
            // position support is in when something has gone wrong.
            'metadata' => ['user_id' => (string) $seller->id],
        ])->id);
    }

    public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->call(fn () => $this->client()->v2->core->accountLinks->create([
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => [
                    // Collect only what the recipient configuration needs.
                    // Naming the configuration is what keeps the form short
                    // for someone who just wants to sell a guitar.
                    'configurations' => ['recipient'],
                    'refresh_url' => $refreshUrl,
                    'return_url' => $returnUrl,
                ],
            ],
        ])->url);
    }

    public function fetchAccountState(string $accountId): AccountState
    {
        return $this->call(function () use ($accountId) {
            // include is not optional politeness: a v2 account comes back
            // without its configurations unless they are asked for, so
            // omitting this reads every capability as absent and quietly
            // reports a perfectly good seller as unable to be paid.
            $account = $this->client()->v2->core->accounts->retrieve($accountId, [
                'include' => ['configuration.recipient', 'requirements'],
            ]);

            // Through toArray rather than property chains. Stripe omits
            // whole branches of this structure for an account that has not
            // got that far yet, and array access with a default says
            // "not yet" where a property chain would raise.
            $data = $account->toArray();
            $capabilities = $data['configuration']['recipient']['capabilities']['stripe_balance'] ?? [];

            return new AccountState(
                transfersEnabled: ($capabilities['stripe_transfers']['status'] ?? null) === 'active',
                payoutsEnabled: ($capabilities['payouts']['status'] ?? null) === 'active',
                // v2 states this as what is still outstanding rather than as
                // a done flag, so nothing outstanding is the closest true
                // thing to the old details_submitted.
                detailsSubmitted: ($data['requirements']['entries'] ?? []) === [],
            );
        });
    }

    public function openPayment(Order $order): PaymentHandle
    {
        $listing = $order->listing;

        return $this->call(function () use ($order, $listing) {
            $intent = $this->client()->paymentIntents->create([
                // Stripe works in minor units. The order's own conversion is
                // used rather than a second one here, so the amount charged
                // and the amount recorded cannot drift apart.
                'amount' => $order->amountInPence(),
                'currency' => strtolower($order->currency),

                // Stripe decides which methods to offer, from what is enabled
                // on the account and what suits the buyer, rather than this
                // code carrying a list it would then have to maintain. It is
                // also what makes wallets appear in the Payment Element
                // without any further work here.
                'automatic_payment_methods' => ['enabled' => true],

                // No application_fee_amount and no on_behalf_of. The charge
                // belongs to the platform outright and the seller's share
                // leaves later as its own transfer, which is what separate
                // charges and transfers means and what lets the money sit
                // still in between.

                // Ties the charge and the later transfer together, so a
                // seller's payout can be traced to the buyer's payment in
                // Stripe's own reporting and not only in this database.
                'transfer_group' => $order->transferGroup(),

                // What the webhook reads to find the order again.
                'metadata' => ['order_id' => (string) $order->id],

                'description' => $this->describe($listing->title),
            ], [
                // One payment per order, however many times a buyer retries a
                // request that failed halfway through.
                'idempotency_key' => "payment_order_{$order->id}",
            ]);

            return new PaymentHandle(
                paymentIntentId: $intent->id,
                clientSecret: $intent->client_secret,
            );
        });
    }

    /**
     * The three PaymentIntent states that mean the platform has the buyer's
     * money, or may be about to.
     *
     * processing earns its place here as much as succeeded does: a payment
     * still settling is one that can still land, and treating "not yet" as
     * "no" is how an order gets cancelled out from under a payment that then
     * arrives with nowhere to go.
     */
    private const HOLDS_MONEY = ['succeeded', 'processing', 'requires_capture'];

    public function abandonPayment(string $paymentIntentId): bool
    {
        try {
            $this->client()->paymentIntents->cancel($paymentIntentId);

            return true;
        } catch (InvalidRequestException) {
            // Stripe refuses to cancel a PaymentIntent that has reached a
            // state it cannot leave, and those states do not all mean the
            // same thing to the caller, so the reason has to be established
            // rather than assumed. Asking afterwards rather than before is
            // deliberate: checking first would leave a window in which the
            // buyer pays between the check and the cancellation.
            try {
                $intent = $this->client()->paymentIntents->retrieve($paymentIntentId);
            } catch (ApiErrorException) {
                // Stripe cannot even find it. Nothing here can take money.
                return true;
            }

            // Already cancelled lands here too, and correctly returns true:
            // the caller asked for it to be uncancellable and it is.
            return ! in_array($intent->status, self::HOLDS_MONEY, true);
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
     * What the payment is called in Stripe's dashboard and on the buyer's
     * receipt, which is the first place anyone looks when a buyer writes in.
     *
     * Trimmed rather than passed through, because Stripe rejects an
     * over-long description and a seller who titles a listing with an essay
     * should not thereby fail the payment.
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
