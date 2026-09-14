<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\EscrowService;
use App\Services\Payments\PaymentGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies one verified Stripe event to the database.
 *
 * The signature was checked before this was queued, so the payload is
 * trusted here. What it is not is ordered or unique: Stripe redelivers events
 * and can deliver them out of sequence, so every handler below has to be
 * safe to run twice and safe to run late. That safety lives in the order
 * lifecycle rather than in this class - each transition is a whitelist check
 * against the order's current state, and an event that arrives after the
 * order has moved on simply finds the transition refused.
 */
class ProcessStripeEvent implements ShouldQueue
{
    use Queueable;

    /**
     * Stripe's own retries are the outer loop, so this only needs to cover
     * transient local failures like a database blip.
     */
    public int $tries = 3;

    /** @param array<string, mixed> $event */
    public function __construct(private readonly array $event) {}

    public function handle(EscrowService $escrow, PaymentGateway $gateway): void
    {
        $type = $this->event['type'] ?? '';
        $object = $this->event['data']['object'] ?? [];

        match ($type) {
            // The money is with the platform. One event covers both the card
            // that clears instantly and the bank debit that takes days: a
            // slow method sits in 'processing' until it settles and only
            // reaches this event when it actually has, so unlike the hosted
            // Checkout this replaced, there is no second event to handle and
            // no "completed but not paid" state to guard against.
            'payment_intent.succeeded' => $this->paymentSucceeded($escrow, $object),

            // Stripe cancelled the payment, or this application did through
            // the reservation sweep. Either way nobody can pay against it
            // now, so the order should not keep holding the listing.
            'payment_intent.canceled' => $this->paymentCanceled($object),

            // payment_intent.payment_failed is deliberately absent. A failed
            // card leaves the intent in requires_payment_method, which is a
            // live payment the buyer can simply try again with a different
            // card, and cancelling their order underneath them would be the
            // wrong response to a typo'd expiry date. An abandoned attempt
            // is the reservation sweep's job, not this event's.

            // Covers refunds issued from the Stripe dashboard as well as ones
            // this application asked for, which is why it records rather than
            // initiates.
            'charge.refunded' => $this->chargeRefunded($escrow, $object),

            // A chargeback. Freezes the order so the auto-release sweep does
            // not pay the seller out from under an unresolved complaint.
            'charge.dispute.created' => $this->disputeOpened($escrow, $object),

            // A seller finished, or failed, Stripe's verification.
            //
            // Three event names for one thing. Connect onboarding runs on
            // Accounts v2, which announces itself through the two bracketed
            // names below; account.updated is the v1 spelling, kept because
            // it costs nothing and this integration would rather hear the
            // news twice than not at all. None of them is trusted for the
            // detail - see accountChanged.
            'account.updated',
            'v2.core.account[configuration.recipient].updated',
            'v2.core.account[configuration.recipient].capability_status_updated' => $this->accountChanged(
                $gateway,
                // v1 puts the account in data.object; a v2 thin event names
                // it in related_object and carries no detail at all.
                $object['id'] ?? $this->event['related_object']['id'] ?? null,
            ),

            // Everything else Stripe is configured to send. Ignored on
            // purpose and without a log line, since the noise would bury the
            // events that do matter.
            default => null,
        };
    }

    /** @param array<string, mixed> $intent */
    private function paymentSucceeded(EscrowService $escrow, array $intent): void
    {
        $order = $this->orderFromIntent($intent);

        if ($order === null) {
            return;
        }

        $id = $intent['id'] ?? null;

        $escrow->markPaid($order, is_string($id) && $id !== '' ? $id : null);
    }

    /** @param array<string, mixed> $intent */
    private function paymentCanceled(array $intent): void
    {
        $order = $this->orderFromIntent($intent);

        if ($order === null) {
            return;
        }

        DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            // Only an unpaid order. This event can arrive after a payment has
            // already been recorded, out of order with the one that recorded
            // it, and cancelling then would strand real money.
            if ($locked === null || $locked->status !== OrderStatus::PENDING) {
                return;
            }

            $locked->transitionTo(OrderStatus::CANCELLED);
        });
    }

    /** @param array<string, mixed> $charge */
    private function chargeRefunded(EscrowService $escrow, array $charge): void
    {
        // Partial refunds fire this event too. Only a full refund ends the
        // order; a partial one is a price adjustment that still leaves a sale
        // in place, and this integration does not make them.
        if (($charge['refunded'] ?? false) !== true) {
            return;
        }

        $order = $this->orderFromCharge($charge);

        if ($order === null) {
            return;
        }

        $escrow->settleRefund($order);
    }

    /** @param array<string, mixed> $dispute */
    private function disputeOpened(EscrowService $escrow, array $dispute): void
    {
        $order = $this->orderFromPaymentIntent($dispute['payment_intent'] ?? null);

        if ($order === null) {
            return;
        }

        $escrow->markDisputed($order);
    }

    /**
     * Re-read a seller's account from Stripe and update the local mirror.
     *
     * The event is treated as a nudge, not as data. It would be quicker to
     * read the capability straight out of the payload, but the payload shape
     * differs between the v1 and v2 spellings of this event and a v2
     * recipient account has no charges_enabled field at all - so the quick
     * version would read a perfectly good seller as unable to be paid and
     * quietly stop them selling. Asking Stripe costs one call on an event
     * that arrives a handful of times per seller, ever.
     */
    private function accountChanged(PaymentGateway $gateway, mixed $accountId): void
    {
        if (! is_string($accountId) || $accountId === '') {
            return;
        }

        $seller = User::where('stripe_account_id', $accountId)->first();

        if ($seller === null) {
            // An account this marketplace did not create, or one whose owner
            // has since been deleted. Worth a line: it means the Stripe
            // account and the database disagree about who exists.
            Log::info('Account event for an unknown connected account.', ['account' => $accountId]);

            return;
        }

        // Deliberately not caught. A failure here leaves the mirror stale
        // rather than wrong, the job retries, and the seller's own payments
        // page refreshes it the next time they look.
        $state = $gateway->fetchAccountState($accountId);

        $seller->stripe_transfers_enabled = $state->transfersEnabled;
        $seller->stripe_payouts_enabled = $state->payoutsEnabled;
        $seller->stripe_synced_at = now();
        $seller->save();
    }

    /**
     * The order a PaymentIntent belongs to.
     *
     * Two ways of finding it, because the two fail differently. Metadata is
     * what this application set when it opened the payment and travels on
     * every intent it creates; the intent id is what the order recorded at
     * the same moment, and covers an event whose metadata was stripped or
     * edited in the dashboard.
     *
     * @param  array<string, mixed>  $intent
     */
    private function orderFromIntent(array $intent): ?Order
    {
        $order = $this->orderFromMetadata($intent['metadata']['order_id'] ?? null);

        if ($order !== null) {
            return $order;
        }

        $intentId = $intent['id'] ?? null;

        $order = $this->orderFromPaymentIntent($intentId);

        if ($order !== null) {
            return $order;
        }

        Log::warning('Stripe payment did not match any order.', ['payment_intent' => $intentId]);

        return null;
    }

    /** @param array<string, mixed> $charge */
    private function orderFromCharge(array $charge): ?Order
    {
        return $this->orderFromPaymentIntent($charge['payment_intent'] ?? null)
            ?? $this->orderFromMetadata($charge['metadata']['order_id'] ?? null);
    }

    private function orderFromPaymentIntent(mixed $paymentIntentId): ?Order
    {
        if (! is_string($paymentIntentId) || $paymentIntentId === '') {
            return null;
        }

        return Order::where('stripe_payment_intent_id', $paymentIntentId)->first();
    }

    private function orderFromMetadata(mixed $orderId): ?Order
    {
        return $orderId === null ? null : Order::find((int) $orderId);
    }
}
