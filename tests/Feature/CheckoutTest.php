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
 * Reserving a listing and getting a buyer to Stripe.
 *
 * The theme running through all of it is that an instrument is a quantity of
 * one, so the interesting cases are not "does the payment work" but "what
 * happens to the second person".
 */
class CheckoutTest extends TestCase
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
        $this->listing = Listing::factory()->for($this->seller, 'seller')->create(['price' => 499.99]);
    }

    public function test_a_checkout_reserves_the_listing_and_returns_a_stripe_url(): void
    {
        $response = $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/checkout")
            ->assertCreated()
            ->assertJsonPath('order.status', 'PENDING')
            ->assertJsonPath('resumed', false);

        $this->assertStringStartsWith('https://checkout.stripe.test/', $response->json('checkout_url'));

        $order = Order::sole();
        $this->assertSame($this->buyer->id, $order->buyer_id);
        $this->assertSame($this->seller->id, $order->seller_id);
        $this->assertTrue($order->reserved_until->isFuture());

        // The listing itself has not been sold. Nothing has been paid, and
        // presenting it as sold would be a lie that a lapsed reservation
        // then has to walk back.
        $this->assertSame('ACTIVE', $this->listing->fresh()->status);
    }

    /**
     * The amount is the one thing here that cannot be approximately right.
     */
    public function test_stripe_is_asked_for_the_exact_price_in_pence(): void
    {
        $this->startCheckout($this->buyer, $this->listing);

        $call = $this->gateway->firstCall('openCheckout');

        $this->assertSame(49999, $call['amount_pence']);
        $this->assertSame('GBP', $call['currency']);
    }

    public function test_the_platform_fee_is_recorded_on_the_order_at_checkout(): void
    {
        config()->set('services.stripe.platform_fee_percent', '5');

        $order = $this->startCheckout($this->buyer, $this->listing);

        // 5% of 499.99 is 24.9995, truncated to 24.99: the rounding goes
        // against the platform, never the seller.
        $this->assertEquals('24.99', $order->platform_fee);
        $this->assertSame('475.00', $order->sellerProceeds());
    }

    /**
     * The fee is stored per order rather than read from config at payout
     * time, so a later change to the rate must not rewrite an existing sale.
     */
    public function test_changing_the_fee_does_not_rewrite_existing_orders(): void
    {
        config()->set('services.stripe.platform_fee_percent', '5');

        $order = $this->startCheckout($this->buyer, $this->listing);

        config()->set('services.stripe.platform_fee_percent', '20');

        $this->assertEquals('24.99', $order->fresh()->platform_fee);
    }

    public function test_a_seller_cannot_buy_their_own_listing(): void
    {
        $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/checkout")
            ->assertStatus(422)
            ->assertJsonValidationErrors('listing');

        $this->assertSame(0, Order::count());
    }

    public function test_a_sold_listing_cannot_be_checked_out(): void
    {
        $sold = Listing::factory()->for($this->seller, 'seller')->sold()->create();

        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$sold->id}/checkout")
            ->assertStatus(422)
            ->assertJsonValidationErrors('listing');
    }

    /**
     * The check that stops the marketplace taking money it cannot pass on.
     *
     * A seller with an account id but no enabled capabilities is the state
     * most likely to slip through, because the obvious check - do they have
     * an account - says yes.
     */
    public function test_a_listing_cannot_be_bought_from_a_seller_stripe_will_not_pay(): void
    {
        $unready = User::factory()->payoutPending()->create();
        $listing = Listing::factory()->for($unready, 'seller')->create();

        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$listing->id}/checkout")
            ->assertStatus(422)
            ->assertJsonValidationErrors('listing');

        $this->assertSame(0, $this->gateway->timesCalled('openCheckout'));
        $this->assertSame(0, Order::count());
    }

    /**
     * Coming back to a checkout you abandoned is not a second purchase.
     *
     * Opening a second session would leave two live ways to pay for one
     * instrument, which is the same failure as letting two buyers through,
     * just with the same person on both ends of it.
     */
    public function test_returning_to_an_unfinished_checkout_resumes_the_same_session(): void
    {
        $first = $this->startCheckout($this->buyer, $this->listing);

        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/checkout")
            ->assertOk()
            ->assertJsonPath('resumed', true)
            ->assertJsonPath('order.id', $first->id);

        $this->assertSame(1, $this->gateway->timesCalled('openCheckout'));
        $this->assertSame(1, Order::count());
    }

    /**
     * A reservation has to actually lapse, or an abandoned checkout takes an
     * instrument off the market permanently.
     */
    public function test_a_lapsed_reservation_frees_the_listing_for_someone_else(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);

        $order->reserved_until = now()->subMinute();
        $order->save();

        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson("/api/listings/{$this->listing->id}/checkout")
            ->assertCreated();

        $this->assertSame(2, Order::count());
    }

    /**
     * When Stripe cannot be reached the reservation has to come back off the
     * listing, or the marketplace's own outage takes an instrument down with
     * it for the length of the reservation window.
     */
    public function test_a_stripe_failure_releases_the_reservation(): void
    {
        $this->gateway->failWith = 'Stripe is down.';

        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/checkout")
            ->assertStatus(503);

        $order = Order::sole();
        $this->assertSame(OrderStatus::CANCELLED, $order->status);

        // And the listing is immediately available to anyone else.
        $this->gateway->failWith = null;
        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson("/api/listings/{$this->listing->id}/checkout")
            ->assertCreated();
    }

    public function test_checking_out_requires_signing_in(): void
    {
        $this->postJson("/api/listings/{$this->listing->id}/checkout")
            ->assertUnauthorized();
    }

    /**
     * The Stripe URL is a live way to pay for an instrument, so it is handed
     * to the buyer who reserved it and to nobody else - including the seller,
     * who can otherwise read the order quite legitimately.
     */
    public function test_the_checkout_url_is_not_exposed_on_the_order_endpoints(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);

        $body = $this->actingAs($this->seller)
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('checkout.stripe.test', $body);
        $this->assertStringNotContainsString('cs_test_', $body);
    }
}
