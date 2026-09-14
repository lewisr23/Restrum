<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Turning "I want to buy this" into a reserved order and a payment the
 * buyer's browser can render.
 *
 * The hard part is not the payment, it is that an instrument is a quantity of
 * one. Two buyers paying for the same guitar means one of them is
 * getting a refund and an apology for something they were told they had
 * bought, so the reservation has to be taken under a lock before anyone is
 * sent anywhere.
 */
class CheckoutService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Reserve the listing for this buyer and open a Stripe PaymentIntent.
     *
     * @throws ValidationException when the listing cannot be bought
     * @throws PaymentGatewayException when Stripe refuses the payment
     */
    public function start(User $buyer, Listing $listing): StartedCheckout
    {
        // Safe to check before the transaction: seller_id never changes, so
        // unlike status it cannot be stale by the time the write happens.
        if ($buyer->id === $listing->seller_id) {
            throw ValidationException::withMessages([
                'listing' => 'You cannot buy your own listing.',
            ]);
        }

        $order = $this->reserve($buyer, $listing);

        // Resuming a checkout the buyer already started. No second payment,
        // because a second payment is a second way to pay for one
        // instrument. Handing the same secret back is safe and is in fact
        // how a declined card is retried: the PaymentIntent returns to
        // requires_payment_method and the buyer simply tries again against
        // it, which is one of the things this model does better than the
        // hosted session it replaced.
        if ($order->stripe_payment_intent_client_secret !== null) {
            return new StartedCheckout($order, $order->stripe_payment_intent_client_secret, isNew: false);
        }

        // Outside the transaction on purpose. This is a network call, and the
        // listing row lock taken above is held by every other path that sells
        // a listing; keeping Stripe inside it would make an unreachable
        // Stripe into an unsellable marketplace.
        //
        // The cost of committing first is an order that exists with no
        // session behind it, and that is the failure this catch clears up.
        try {
            $handle = $this->gateway->openPayment($order);
        } catch (PaymentGatewayException $e) {
            $this->abandon($order);

            throw $e;
        }

        $order->stripe_payment_intent_id = $handle->paymentIntentId;
        $order->stripe_payment_intent_client_secret = $handle->clientSecret;
        $order->save();

        return new StartedCheckout($order, $handle->clientSecret, isNew: true);
    }

    /**
     * Take the listing off the market for this buyer, or explain why not.
     *
     * Everything that decides whether the sale may happen is read inside the
     * lock. Reading any of it outside means deciding on a state that another
     * request is free to change before the order is written, which is the
     * same read-then-write race the direct sale path already guards against.
     */
    private function reserve(User $buyer, Listing $listing): Order
    {
        return DB::transaction(function () use ($buyer, $listing) {
            $locked = Listing::whereKey($listing->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'listing' => 'This listing is no longer available.',
                ]);
            }

            $blocking = Order::where('listing_id', $locked->id)->blockingListing()->first();

            if ($blocking !== null) {
                // The buyer's own live reservation is not an obstacle, it is
                // the thing they are coming back to. Anyone else's is.
                if ($blocking->buyer_id === $buyer->id && $blocking->reservationIsLive()) {
                    return $blocking;
                }

                throw ValidationException::withMessages([
                    'listing' => $blocking->status === OrderStatus::PENDING
                        ? 'Someone else is checking out for this item. Try again in a few minutes.'
                        : 'This listing is no longer available.',
                ]);
            }

            $seller = User::findOrFail($locked->seller_id);

            // Checked here rather than at payout time because the alternative
            // is taking a buyer's money for an instrument whose seller has no
            // way to receive it, and then owning that refund.
            if (! $seller->canReceivePayments()) {
                throw ValidationException::withMessages([
                    'listing' => 'This seller has not finished setting up payments yet, so the item cannot be bought right now.',
                ]);
            }

            $amount = (string) $locked->price;

            $order = new Order;
            $order->listing_id = $locked->id;
            $order->buyer_id = $buyer->id;
            // Denormalised deliberately, see the orders migration: who sold
            // it is a fact about the sale, not a pointer to the listing.
            $order->seller_id = $seller->id;
            $order->amount = $amount;
            $order->platform_fee = $this->platformFee($amount);
            $order->currency = 'GBP';
            $order->status = OrderStatus::PENDING;
            $order->reserved_until = now()->addMinutes(
                (int) config('services.stripe.reservation_minutes')
            );
            $order->save();

            return $order;
        });
    }

    /**
     * Release reservations that have lapsed, and cancel the payments behind
     * them.
     *
     * Lapsing alone already unblocks the listing, so this is not what makes
     * an abandoned checkout harmless. What it does is shut the door: an open
     * payment is a live way to pay for an instrument that is back on the
     * market, and leaving one up is how two people end up paying for one
     * guitar half an hour apart.
     *
     * @return int how many reservations were closed
     */
    public function expireLapsedReservations(): int
    {
        $lapsed = Order::query()
            ->where('status', OrderStatus::PENDING)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', now())
            ->limit(200)
            ->get();

        $closed = 0;

        foreach ($lapsed as $order) {
            if ($this->abandon($order)) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Cancel an unpaid order, leaving a paid one alone.
     *
     * Stripe is asked first and its answer is obeyed. If the buyer completed
     * the payment while this was being decided, cancelling the order locally
     * would put a real payment into a terminal state that no webhook is
     * allowed to move it out of, and the money would be stuck.
     *
     * @return bool whether the order was actually cancelled
     */
    private function abandon(Order $order): bool
    {
        if ($order->stripe_payment_intent_id !== null) {
            try {
                if (! $this->gateway->abandonPayment($order->stripe_payment_intent_id)) {
                    // Paid after all. Leave it to the webhook, which will
                    // move it to PAID and take the listing off the market.
                    return false;
                }
            } catch (PaymentGatewayException $e) {
                // Stripe is unreachable. Leaving the order PENDING is the
                // safe failure: the reservation has already lapsed, so the
                // listing is available again, and the next sweep retries.
                Log::warning('Could not cancel the Stripe payment for order '.$order->id, [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            // A webhook may have moved it while Stripe was being called.
            if ($locked === null || $locked->status !== OrderStatus::PENDING) {
                return false;
            }

            $locked->transitionTo(OrderStatus::CANCELLED);

            return true;
        });
    }

    /**
     * The platform's cut of a sale.
     *
     * bcdiv truncates rather than rounds, so a fee always lands on or below
     * the exact percentage. That is the right direction for the rounding to
     * go: the fraction of a penny in dispute is the platform's own, and
     * taking it would be the marketplace rounding in its own favour.
     */
    private function platformFee(string $amount): string
    {
        $percent = (string) config('services.stripe.platform_fee_percent');

        return bcdiv(bcmul($amount, $percent, 6), '100', 2);
    }

}
