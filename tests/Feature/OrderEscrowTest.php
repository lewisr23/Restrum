<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithPayments;
use Tests\TestCase;

/**
 * The money between the payment and the payout.
 *
 * Most of these are about being told something twice, or late, or out of
 * order, because that is what a webhook integration actually has to survive.
 * Stripe guarantees delivery, not sequence, and an escrow system that assumes
 * sequence is one redelivered event away from paying a seller twice.
 */
class OrderEscrowTest extends TestCase
{
    use InteractsWithPayments, RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePayments();

        $this->seller = User::factory()->payoutReady()->create();
        $this->buyer = User::factory()->create();
        $this->listing = Listing::factory()->for($this->seller, 'seller')->create(['price' => 200]);
    }

    public function test_an_unsigned_webhook_is_refused(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);

        $this->sendWebhook($this->stripeEvent('checkout.session.completed', [
            'id' => $order->stripe_checkout_session_id,
            'payment_status' => 'paid',
            'metadata' => ['order_id' => (string) $order->id],
        ]), signature: 'invalid')->assertStatus(400);

        // The point of the check: an unverified caller cannot mark an order
        // paid, which is otherwise a free instrument.
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
    }

    public function test_a_completed_payment_sells_the_listing(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);

        $this->reportPayment($order)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertSame("pi_test_{$order->id}", $order->stripe_payment_intent_id);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('SOLD', $this->listing->fresh()->status);
    }

    /**
     * Stripe redelivers. The second copy of an event must change nothing,
     * including the timestamps, which are what support reads to work out when
     * something actually happened.
     */
    public function test_a_redelivered_payment_event_changes_nothing(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);

        $this->reportPayment($order)->assertOk();
        $paidAt = $order->fresh()->paid_at;

        $this->travel(5)->minutes();

        $this->reportPayment($order)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertEquals($paidAt, $order->paid_at);
    }

    /**
     * A completed session is not necessarily a completed payment. Slower
     * methods settle days later, and handing over an instrument on the
     * strength of the session closing would be handing it over for nothing.
     */
    public function test_a_session_that_completed_without_payment_is_not_treated_as_paid(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);

        $this->sendWebhook($this->stripeEvent('checkout.session.completed', [
            'id' => $order->stripe_checkout_session_id,
            'payment_status' => 'unpaid',
            'metadata' => ['order_id' => (string) $order->id],
        ]))->assertOk();

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertSame('ACTIVE', $this->listing->fresh()->status);
    }

    public function test_a_late_async_payment_still_sells_the_listing(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);

        $this->sendWebhook($this->stripeEvent('checkout.session.async_payment_succeeded', [
            'id' => $order->stripe_checkout_session_id,
            'payment_intent' => 'pi_test_async',
            'metadata' => ['order_id' => (string) $order->id],
        ]))->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->assertSame('SOLD', $this->listing->fresh()->status);
    }

    public function test_an_expired_session_cancels_an_unpaid_order(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);

        $this->sendWebhook($this->stripeEvent('checkout.session.expired', [
            'id' => $order->stripe_checkout_session_id,
            'metadata' => ['order_id' => (string) $order->id],
        ]))->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame('ACTIVE', $this->listing->fresh()->status);
    }

    /**
     * Events can arrive out of order. An expiry landing after a payment must
     * not cancel an order Stripe has already taken money for.
     */
    public function test_an_expiry_arriving_after_a_payment_is_ignored(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);
        $this->reportPayment($order)->assertOk();

        $this->sendWebhook($this->stripeEvent('checkout.session.expired', [
            'id' => $order->stripe_checkout_session_id,
            'metadata' => ['order_id' => (string) $order->id],
        ]))->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
    }

    public function test_the_buyer_confirms_receipt_and_only_the_buyer(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);
        $this->reportPayment($order)->assertOk();

        // A seller able to confirm their own delivery would make buyer
        // protection decorative.
        $this->actingAs($this->seller)
            ->postJson("/api/orders/{$order->id}/confirm")
            ->assertStatus(422);

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);

        $this->actingAs($this->buyer)
            ->postJson("/api/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED');

        $this->assertNotNull($order->fresh()->confirmed_at);
    }

    public function test_a_stranger_cannot_see_an_order(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/orders/{$order->id}")
            ->assertForbidden();
    }

    public function test_confirming_releases_the_sellers_share_on_the_next_sweep(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);
        $this->reportPayment($order)->assertOk();

        $this->actingAs($this->buyer)->postJson("/api/orders/{$order->id}/confirm")->assertOk();

        $this->artisan('orders:sweep')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::RELEASED, $order->status);
        $this->assertNotNull($order->released_at);
        $this->assertNotNull($order->stripe_transfer_id);

        $call = $this->gateway->firstCall('payOutSeller');

        // 200.00 less the 5% fee, in pence. The seller is paid their share
        // and not the gross, and Stripe's own processing fee comes out of the
        // platform's side rather than theirs.
        $this->assertSame(19000, $call['amount_pence']);
        $this->assertSame($this->seller->stripe_account_id, $call['destination']);

        // Derived from the order id, so a retried worker cannot pay twice.
        $this->assertSame("transfer_order_{$order->id}", $call['idempotency_key']);
    }

    public function test_a_second_sweep_does_not_pay_the_seller_again(): void
    {
        $order = Order::factory()
            ->forListing($this->listing, $this->buyer)
            ->confirmed()
            ->create();

        $this->artisan('orders:sweep')->assertSuccessful();
        $this->artisan('orders:sweep')->assertSuccessful();

        $this->assertSame(1, $this->gateway->timesCalled('payOutSeller'));
        $this->assertSame(OrderStatus::RELEASED, $order->fresh()->status);
    }

    /**
     * Without this an order sits in escrow forever whenever a buyer simply
     * stops replying, which punishes the seller for the buyer's silence.
     */
    public function test_a_paid_order_the_buyer_never_confirms_releases_itself_eventually(): void
    {
        config()->set('services.stripe.auto_release_days', 14);

        $order = Order::factory()
            ->forListing($this->listing, $this->buyer)
            ->paid()
            ->create(['paid_at' => now()->subDays(15)]);

        $this->artisan('orders:sweep')->assertSuccessful();

        $this->assertSame(OrderStatus::RELEASED, $order->fresh()->status);
    }

    public function test_an_order_still_inside_the_window_is_left_alone(): void
    {
        config()->set('services.stripe.auto_release_days', 14);

        $order = Order::factory()
            ->forListing($this->listing, $this->buyer)
            ->paid()
            ->create(['paid_at' => now()->subDays(3)]);

        $this->artisan('orders:sweep')->assertSuccessful();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->assertSame(0, $this->gateway->timesCalled('payOutSeller'));
    }

    /**
     * A dispute is a decision, not a workflow step. The point of the state is
     * that the sweep stops: nothing should pay a seller out from under an
     * unresolved complaint.
     */
    public function test_a_disputed_order_is_not_swept_into_a_payout(): void
    {
        $order = Order::factory()
            ->forListing($this->listing, $this->buyer)
            ->paid()
            ->create(['paid_at' => now()->subDays(30)]);

        $this->sendWebhook($this->stripeEvent('charge.dispute.created', [
            'id' => 'dp_test_1',
            'payment_intent' => $order->stripe_payment_intent_id,
        ]))->assertOk();

        $this->assertSame(OrderStatus::DISPUTED, $order->fresh()->status);

        $this->artisan('orders:sweep')->assertSuccessful();

        $this->assertSame(OrderStatus::DISPUTED, $order->fresh()->status);
        $this->assertSame(0, $this->gateway->timesCalled('payOutSeller'));
    }

    public function test_a_full_refund_puts_the_listing_back_up_for_sale(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);
        $this->reportPayment($order)->assertOk();

        $this->assertSame('SOLD', $this->listing->fresh()->status);

        $this->sendWebhook($this->stripeEvent('charge.refunded', [
            'id' => 'ch_test_1',
            'refunded' => true,
            'payment_intent' => $order->fresh()->stripe_payment_intent_id,
        ]))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::REFUNDED, $order->status);
        $this->assertNotNull($order->refunded_at);
        $this->assertSame('ACTIVE', $this->listing->fresh()->status);
    }

    /**
     * Partial refunds fire the same event. This integration does not make
     * them, and treating one as the end of the sale would put a listing that
     * is still sold back on the market.
     */
    public function test_a_partial_refund_does_not_end_the_order(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);
        $this->reportPayment($order)->assertOk();

        $this->sendWebhook($this->stripeEvent('charge.refunded', [
            'id' => 'ch_test_1',
            'refunded' => false,
            'payment_intent' => $order->fresh()->stripe_payment_intent_id,
        ]))->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->assertSame('SOLD', $this->listing->fresh()->status);
    }

    public function test_stripe_reporting_a_verified_seller_updates_their_account(): void
    {
        $seller = User::factory()->payoutPending()->create();

        $this->assertFalse($seller->canReceivePayments());

        $this->sendWebhook($this->stripeEvent('account.updated', [
            'id' => $seller->stripe_account_id,
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
        ]))->assertOk();

        $this->assertTrue($seller->fresh()->canReceivePayments());
    }

    public function test_the_sweep_expires_lapsed_reservations_and_closes_their_sessions(): void
    {
        $order = Order::factory()
            ->forListing($this->listing, $this->buyer)
            ->lapsed()
            ->create();

        $this->artisan('orders:sweep')->assertSuccessful();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertContains($order->stripe_checkout_session_id, $this->gateway->abandoned);
    }

    /**
     * The dangerous case in the whole sweep. If the buyer paid in the seconds
     * before the reservation lapsed, cancelling the order locally would put a
     * real payment into a terminal state that no webhook can move it out of,
     * and the money would be stuck with the platform.
     */
    public function test_the_sweep_leaves_an_order_alone_when_stripe_says_it_was_paid(): void
    {
        $order = Order::factory()
            ->forListing($this->listing, $this->buyer)
            ->lapsed()
            ->create();

        $this->gateway->abandonSucceeds = false;

        $this->artisan('orders:sweep')->assertSuccessful();

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);

        // And the payment that was in flight still lands normally.
        $this->reportPayment($order)->assertOk();
        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
    }
}
