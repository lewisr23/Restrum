<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\EscrowService;
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

    public function handle(EscrowService $escrow): void
    {
        $type = $this->event['type'] ?? '';
        $object = $this->event['data']['object'] ?? [];

        match ($type) {
            // The ordinary card payment: the buyer finished Checkout and the
            // money is with the platform.
            'checkout.session.completed' => $this->sessionCompleted($escrow, $object),

            // Slower payment methods clear after the session closes, so the
            // session completing is not the same as the money arriving.
            'checkout.session.async_payment_succeeded' => $this->markPaid($escrow, $object),

            // Stripe closed the session itself. The reservation sweep would
            // reach the same conclusion eventually; this just gets there first.
            'checkout.session.expired' => $this->sessionExpired($object),

            // Covers refunds issued from the Stripe dashboard as well as ones
            // this application asked for, which is why it records rather than
            // initiates.
            'charge.refunded' => $this->chargeRefunded($escrow, $object),

            // A chargeback. Freezes the order so the auto-release sweep does
            // not pay the seller out from under an unresolved complaint.
            'charge.dispute.created' => $this->disputeOpened($escrow, $object),

            // A seller finished, or failed, Stripe's verification.
            'account.updated' => $this->accountUpdated($object),

            // Everything else Stripe is configured to send. Ignored on
            // purpose and without a log line, since the noise would bury the
            // events that do matter.
            default => null,
        };
    }

    /** @param array<string, mixed> $session */
    private function sessionCompleted(EscrowService $escrow, array $session): void
    {
        // A completed session is not a completed payment. Bank debits and
        // similar methods leave it 'unpaid' for days, and treating that as
        // money in hand would hand an instrument over for nothing.
        if (($session['payment_status'] ?? null) !== 'paid') {
            return;
        }

        $this->markPaid($escrow, $session);
    }

    /** @param array<string, mixed> $session */
    private function markPaid(EscrowService $escrow, array $session): void
    {
        $order = $this->orderFromSession($session);

        if ($order === null) {
            return;
        }

        $escrow->markPaid($order, $this->paymentIntentId($session));
    }

    /** @param array<string, mixed> $session */
    private function sessionExpired(array $session): void
    {
        $order = $this->orderFromSession($session);

        if ($order === null) {
            return;
        }

        DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            // Only an unpaid order. Stripe can expire a session whose payment
            // is still settling, and cancelling that would strand the money.
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

    /** @param array<string, mixed> $account */
    private function accountUpdated(array $account): void
    {
        $accountId = $account['id'] ?? null;

        if (! is_string($accountId)) {
            return;
        }

        $seller = User::where('stripe_account_id', $accountId)->first();

        if ($seller === null) {
            // An account this marketplace did not create, or one whose owner
            // has since been deleted. Worth a line: it means the Stripe
            // account and the database disagree about who exists.
            Log::info('account.updated for an unknown connected account.', ['account' => $accountId]);

            return;
        }

        $seller->stripe_charges_enabled = (bool) ($account['charges_enabled'] ?? false);
        $seller->stripe_payouts_enabled = (bool) ($account['payouts_enabled'] ?? false);
        $seller->stripe_synced_at = now();
        $seller->save();
    }

    /**
     * The order a Checkout Session belongs to.
     *
     * Three ways of finding it, because the three are not equally reliable.
     * Metadata is what this application set and is present on every session
     * it creates; client_reference_id is the same value in the field Stripe's
     * dashboard displays; the session id is the fallback for a session
     * created before either was readable.
     *
     * @param  array<string, mixed>  $session
     */
    private function orderFromSession(array $session): ?Order
    {
        $orderId = $session['metadata']['order_id'] ?? $session['client_reference_id'] ?? null;

        if ($orderId !== null) {
            $order = Order::find((int) $orderId);

            if ($order !== null) {
                return $order;
            }
        }

        $sessionId = $session['id'] ?? null;

        if (is_string($sessionId)) {
            $order = Order::where('stripe_checkout_session_id', $sessionId)->first();

            if ($order !== null) {
                return $order;
            }
        }

        Log::warning('Stripe session did not match any order.', ['session' => $sessionId]);

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

    /**
     * Stripe expands this field in some deliveries and leaves it a bare id in
     * others, so both shapes have to be handled rather than assumed.
     *
     * @param  array<string, mixed>  $session
     */
    private function paymentIntentId(array $session): ?string
    {
        $intent = $session['payment_intent'] ?? null;

        if (is_array($intent)) {
            $intent = $intent['id'] ?? null;
        }

        return is_string($intent) && $intent !== '' ? $intent : null;
    }
}
