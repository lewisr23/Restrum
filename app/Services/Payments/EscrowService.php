<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use App\Notifications\GearSold;
use App\Notifications\OrderDispatched;
use App\Notifications\OrderRefunded;
use App\Notifications\PaymentHeld;
use App\Notifications\PayoutSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The money between the payment and the payout.
 *
 * Everything that moves an order along its lifecycle lives here rather than
 * in the controllers and the webhook job, because the same transitions are
 * reached from three directions - a buyer clicking confirm, Stripe reporting
 * a payment, a scheduled sweep - and three copies of "is this allowed" is
 * three chances to get it wrong about money.
 *
 * Lock ordering, which matters because deadlocks here are deadlocks over
 * payments: any operation touching both a listing and an order takes the
 * LISTING lock first. That is the order the direct sale path and the checkout
 * reservation already use, so nothing in the application ever waits on the
 * two in opposite directions.
 */
class EscrowService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Tell the people involved what just happened to their sale.
     *
     * Called AFTER the transaction rather than inside it, and only when the
     * transition actually took place. Both halves matter. Stripe redelivers
     * webhooks, so a method that ran its notifications unconditionally would
     * email a seller twice about one sale; and a notification queued inside
     * a transaction can reach a worker before the rows it describes are
     * visible to anyone else.
     *
     * A missing recipient is not an error here. An account can be deleted
     * between a sale and its payout, and refusing to notify the other party
     * because of it would be the wrong way round.
     *
     * @param  callable(Order): array<int, array{0: ?User, 1: object}>  $plan
     */
    private function announce(Order $order, callable $plan): void
    {
        foreach ($plan($order) as [$recipient, $notification]) {
            $recipient?->notify($notification);
        }
    }

    /**
     * Record that Stripe took the buyer's money, and take the listing off
     * the market.
     *
     * Called only from the webhook. Returns false when the order had already
     * moved on, which is the normal case for a redelivered event rather than
     * an error: Stripe retries deliveries, and the correct response to being
     * told something twice is to do it once.
     */
    public function markPaid(Order $order, ?string $paymentIntentId): bool
    {
        $paid = DB::transaction(function () use ($order, $paymentIntentId) {
            $locked = $this->lockOrderAndListing($order);

            if ($locked === null || ! $locked->status->canTransitionTo(OrderStatus::PAID)) {
                return false;
            }

            if ($paymentIntentId !== null) {
                $locked->stripe_payment_intent_id = $paymentIntentId;
            }

            // The listing is sold at the moment the money arrives, not at the
            // moment the buyer clicked buy. Until then it was only reserved,
            // and a reservation that never turns into a payment has to leave
            // the instrument exactly as it found it.
            $listing = $locked->listing;
            $listing->status = 'SOLD';
            $listing->save();

            $locked->transitionTo(OrderStatus::PAID);

            return true;
        });

        // Only on the transition, never on a redelivered webhook. Stripe
        // retries, and being told twice that a guitar sold must not mean
        // being emailed twice that it did.
        if ($paid) {
            $this->announce($order->fresh()->load('listing'), fn (Order $o) => [
                [$o->seller, new GearSold($o)],
                [$o->buyer, new PaymentHeld($o)],
            ]);
        }

        return $paid;
    }

    /**
     * The buyer says the instrument arrived as described.
     *
     * This is the only thing that releases money early, so it is the only
     * thing only the buyer may do. A seller able to confirm their own
     * delivery would make buyer protection decorative.
     *
     * @throws ValidationException
     */
    public function confirmReceipt(Order $order, User $actor): Order
    {
        if ($order->buyer_id !== $actor->id) {
            throw ValidationException::withMessages([
                'order' => 'Only the buyer can confirm an order.',
            ]);
        }

        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::PAID) {
                throw ValidationException::withMessages([
                    'order' => match ($locked->status) {
                        OrderStatus::PENDING => 'This order has not been paid yet.',
                        OrderStatus::CONFIRMED, OrderStatus::RELEASED => 'This order has already been confirmed.',
                        default => 'This order can no longer be confirmed.',
                    },
                ]);
            }

            $locked->transitionTo(OrderStatus::CONFIRMED);

            return $locked;
        });
    }

    /**
     * Send the seller their share.
     *
     * The Stripe call happens outside any transaction, so two sweepers
     * running at once could both reach it. What stops that paying a seller
     * twice is the idempotency key, not a lock: the key is derived from the
     * order id, so the second call returns Stripe's record of the first
     * transfer rather than making another one. A row lock could not offer the
     * same guarantee, because it cannot be held across the network call
     * without making Stripe's latency into database contention.
     *
     * @throws PaymentGatewayException
     */
    public function release(Order $order): bool
    {
        if ($order->status !== OrderStatus::CONFIRMED) {
            return false;
        }

        $transferId = $this->gateway->payOutSeller($order, "transfer_order_{$order->id}");

        $released = DB::transaction(function () use ($order, $transferId) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(OrderStatus::RELEASED)) {
                // The transfer went through but the order had already moved.
                // Worth a log rather than an exception: the money reached the
                // right place, and the idempotency key means it reached it
                // exactly once, so this is a bookkeeping mismatch to look at
                // rather than an incident.
                Log::warning('Transfer made for an order that could not be released.', [
                    'order_id' => $locked->id,
                    'status' => $locked->status->value,
                    'transfer_id' => $transferId,
                ]);

                return false;
            }

            $locked->stripe_transfer_id = $transferId;
            $locked->transitionTo(OrderStatus::RELEASED);

            return true;
        });

        if ($released) {
            $this->announce($order->fresh()->load('listing'), fn (Order $o) => [
                [$o->seller, new PayoutSent($o)],
            ]);
        }

        return $released;
    }

    /**
     * Give the buyer their money back and put the instrument back up for
     * sale.
     *
     * Only reachable from states where the platform still holds the funds, so
     * there is never a seller transfer to claw back first. A refund after a
     * payout is a different and much harder problem, and pretending this
     * method covers it would be worse than it not covering it.
     *
     * @throws PaymentGatewayException
     */
    public function refund(Order $order): bool
    {
        // The transfer id, not the status, is what says the seller has been
        // paid. A dispute raised after a payout moves the order to DISPUTED,
        // from which the lifecycle does allow a refund, so checking the status
        // alone would let exactly the case this guards against slip through.
        if ($order->stripe_transfer_id !== null) {
            throw new LogicException(
                "Order {$order->id} has already paid out to the seller; refunding the buyer here would "
                .'leave the platform out of pocket. The transfer has to be reversed first.'
            );
        }

        if (! $order->status->canTransitionTo(OrderStatus::REFUNDED)) {
            return false;
        }

        $this->gateway->refundBuyer($order, "refund_order_{$order->id}");

        return $this->settleRefund($order);
    }

    /**
     * Record a refund that has already happened at Stripe.
     *
     * Separate from refund() because refunds can start on Stripe's side - an
     * operator issuing one from the dashboard, a dispute resolving - and the
     * resulting webhook must not try to refund the same charge again.
     */
    public function settleRefund(Order $order): bool
    {
        $refunded = DB::transaction(function () use ($order) {
            $locked = $this->lockOrderAndListing($order);

            if ($locked === null || ! $locked->status->canTransitionTo(OrderStatus::REFUNDED)) {
                return false;
            }

            $locked->transitionTo(OrderStatus::REFUNDED);

            $this->relist($locked->listing);

            return true;
        });

        if ($refunded) {
            $this->announce($order->fresh()->load('listing'), fn (Order $o) => [
                [$o->buyer, new OrderRefunded($o)],
            ]);
        }

        return $refunded;
    }

    /**
     * The buyer raised a problem, or Stripe reported a chargeback.
     *
     * Nothing automatic happens from here. A dispute is a decision, and the
     * point of the state is to stop the auto-release sweep paying the seller
     * out from under an unresolved complaint.
     */
    public function markDisputed(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->status->canTransitionTo(OrderStatus::DISPUTED)) {
                return false;
            }

            $locked->transitionTo(OrderStatus::DISPUTED);

            return true;
        });
    }

    /**
     * Mark an order as dispatched, with tracking where there is any.
     *
     * This is what starts the release clock. Before it existed the clock
     * started at payment, which meant a seller who posted nothing was paid
     * automatically a fortnight later unless the buyer actively complained:
     * silence favoured whoever already had the buyer's money.
     */
    public function markDispatched(Order $order, ?string $carrier, ?string $trackingNumber): Order
    {
        $dispatched = DB::transaction(function () use ($order, $carrier, $trackingNumber) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::PAID) {
                throw ValidationException::withMessages([
                    'order' => 'Only a paid order that has not been completed can be marked as dispatched.',
                ]);
            }

            // Re-dispatching is allowed on purpose: a seller who fat-fingers
            // a tracking number needs to be able to correct it, and the
            // alternative is a support request. The clock restarting is the
            // right behaviour anyway, since the buyer is now waiting on the
            // corrected parcel.
            $locked->dispatched_at = now();
            $locked->tracking_carrier = $carrier;
            $locked->tracking_number = $trackingNumber;
            $locked->save();

            return $locked;
        });

        $this->announce($dispatched->load('listing'), fn (Order $o) => [
            [$o->buyer, new OrderDispatched($o)],
        ]);

        return $dispatched;
    }

    /**
     * Refund buyers whose seller never posted anything.
     *
     * The counterpart to autoConfirmOverdue, and the reason that one is safe
     * to run at all. A paid order with no dispatch record after the deadline
     * is the commonest marketplace scam there is - take the money, send
     * nothing, wait for the clock. Here the clock runs the other way.
     *
     * Collection-only orders are skipped: there is no parcel to dispatch and
     * the buyer turns up in person, so their protection is simply not
     * confirming receipt.
     *
     * @return array{refunded: int, failed: int}
     */
    public function refundUndispatched(): array
    {
        $cutoff = now()->subDays((int) config('services.stripe.dispatch_deadline_days'));

        $stale = Order::query()
            ->with('listing')
            ->where('status', OrderStatus::PAID)
            ->whereNull('dispatched_at')
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', $cutoff)
            ->limit(100)
            ->get()
            ->reject(fn (Order $order) => (bool) $order->listing?->collection_only);

        $refunded = 0;
        $failed = 0;

        foreach ($stale as $order) {
            try {
                if ($this->refund($order)) {
                    $refunded++;
                }
            } catch (PaymentGatewayException $e) {
                // Same reasoning as releaseDue: one stuck refund must not
                // stop every other buyer getting their money back.
                $failed++;

                Log::error('Could not refund undispatched order '.$order->id, [
                    'order_id' => $order->id,
                    'seller_id' => $order->seller_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['refunded' => $refunded, 'failed' => $failed];
    }

    /**
     * Confirm dispatched orders the buyer never came back to.
     *
     * Without this an order sits in escrow forever whenever a buyer simply
     * stops replying, which punishes the seller for the buyer's silence. The
     * window is long enough that a buyer with a genuine problem has had every
     * chance to say so, and raising a dispute stops the clock.
     *
     * The clock runs from DISPATCH, not payment. Keyed off payment it would
     * pay out a seller who never posted, which is the whole scam this pair of
     * methods exists to close. Collection-only orders have no dispatch, so
     * they keep the payment clock.
     *
     * @return int how many orders were auto-confirmed
     */
    public function autoConfirmOverdue(): int
    {
        $cutoff = now()->subDays((int) config('services.stripe.auto_release_days'));

        $overdue = Order::query()
            ->with('listing')
            ->where('status', OrderStatus::PAID)
            ->whereNotNull('paid_at')
            ->where(function ($query) use ($cutoff) {
                $query
                    ->where(fn ($q) => $q->whereNotNull('dispatched_at')->where('dispatched_at', '<=', $cutoff))
                    ->orWhere(fn ($q) => $q->whereNull('dispatched_at')->where('paid_at', '<=', $cutoff));
            })
            ->limit(200)
            ->get()
            // An undispatched posted order is refundUndispatched's business,
            // never this method's. Without this the two would race and the
            // longer-running clock could still pay a seller who sent nothing.
            ->reject(fn (Order $order) => $order->dispatched_at === null
                && ! (bool) $order->listing?->collection_only);

        $confirmed = 0;

        foreach ($overdue as $order) {
            $moved = DB::transaction(function () use ($order) {
                $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

                if ($locked === null || $locked->status !== OrderStatus::PAID) {
                    return false;
                }

                $locked->transitionTo(OrderStatus::CONFIRMED);

                return true;
            });

            if ($moved) {
                $confirmed++;
            }
        }

        return $confirmed;
    }

    /**
     * Pay out every order that is due one.
     *
     * @return array{released: int, failed: int}
     */
    public function releaseDue(): array
    {
        $due = Order::query()
            ->with('seller')
            ->where('status', OrderStatus::CONFIRMED)
            ->limit(100)
            ->get();

        $released = 0;
        $failed = 0;

        foreach ($due as $order) {
            try {
                if ($this->release($order)) {
                    $released++;
                }
            } catch (PaymentGatewayException $e) {
                // One seller's account being in a bad state must not stop
                // every other seller being paid, so this is counted and the
                // sweep carries on. The order stays CONFIRMED and is picked
                // up again next time.
                $failed++;

                Log::error('Could not release order '.$order->id, [
                    'order_id' => $order->id,
                    'seller_id' => $order->seller_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['released' => $released, 'failed' => $failed];
    }

    /**
     * Put a listing back up for sale after a refund.
     *
     * Only if nothing else is holding it. A listing can carry more than one
     * order over its life, and relisting one that a second buyer has already
     * paid for would put a sold instrument back on the market.
     */
    private function relist(Listing $listing): void
    {
        $stillHeld = Order::where('listing_id', $listing->id)->blockingListing()->exists();

        if ($stillHeld || $listing->status !== 'SOLD') {
            return;
        }

        $listing->status = 'ACTIVE';
        $listing->save();
    }

    /**
     * Lock the listing, then the order, and return the order with its listing
     * already attached.
     *
     * Always in that order. See the note on the class.
     */
    private function lockOrderAndListing(Order $order): ?Order
    {
        Listing::whereKey($order->listing_id)->lockForUpdate()->firstOrFail();

        $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

        return $locked?->load('listing');
    }
}
