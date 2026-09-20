<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithPayments;
use Tests\TestCase;

/**
 * The seller-never-posted scam, and the clock that used to reward it.
 *
 * Auto-confirm used to key off paid_at, so a seller could take the money,
 * send nothing, and be paid automatically once the window elapsed unless the
 * buyer actively complained in time. Silence favoured whoever was already
 * holding the buyer's money. These tests pin the reversal.
 */
class DispatchProtectionTest extends TestCase
{
    use InteractsWithPayments, RefreshDatabase;

    private User $buyer;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePayments();

        $this->buyer = User::factory()->create();
        $this->seller = User::factory()->payoutReady()->create();
    }

    private function paidOrder(array $listingOverrides = [])
    {
        $listing = Listing::factory()->for($this->seller, 'seller')->create($listingOverrides);

        return $this->buyOutright($this->buyer, $listing->fresh());
    }

    private function sweep(): void
    {
        $this->artisan('orders:sweep')->assertSuccessful();
    }

    public function test_a_seller_can_mark_an_order_dispatched_with_tracking(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->seller)
            ->postJson("/api/orders/{$order->id}/dispatch", [
                'tracking_carrier' => 'Royal Mail',
                'tracking_number' => 'AB123456789GB',
            ])
            ->assertOk()
            ->assertJsonPath('data.tracking_number', 'AB123456789GB');

        $this->assertNotNull($order->fresh()->dispatched_at);
    }

    public function test_a_buyer_cannot_claim_the_seller_dispatched_it(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->buyer)
            ->postJson("/api/orders/{$order->id}/dispatch")
            ->assertForbidden();

        $this->assertNull($order->fresh()->dispatched_at);
    }

    /**
     * The scam, run end to end. Before dispatch tracking existed this test
     * would have ended with the seller paid.
     */
    public function test_a_seller_who_never_posts_is_not_paid_and_the_buyer_is_refunded(): void
    {
        $order = $this->paidOrder();

        // Well past both the dispatch deadline and the release window.
        $this->travel(30)->days();
        $this->sweep();

        $order->refresh();

        $this->assertSame(OrderStatus::REFUNDED, $order->status);
        $this->assertNotContains($order->id, $this->gateway->transfers ?? []);
    }

    public function test_the_release_clock_runs_from_dispatch_not_payment(): void
    {
        $order = $this->paidOrder();

        // Dispatched late, but within the deadline, so no refund.
        $this->travel(6)->days();
        $this->actingAs($this->seller)
            ->postJson("/api/orders/{$order->id}/dispatch", ['tracking_number' => 'AB1'])
            ->assertOk();

        // 13 more days: past 14 from payment, but not from dispatch.
        $this->travel(13)->days();
        $this->sweep();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);

        // And now past 14 from dispatch.
        $this->travel(3)->days();
        $this->sweep();

        $this->assertNotSame(OrderStatus::PAID, $order->fresh()->status);
    }

    /**
     * There is no parcel to dispatch when the buyer turns up for it, so a
     * collection order must not be refunded for the lack of one.
     */
    public function test_collection_only_orders_are_not_refunded_for_not_being_posted(): void
    {
        $order = $this->paidOrder(['collection_only' => true]);

        $this->travel(10)->days();
        $this->sweep();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
    }

    public function test_dispatch_is_refused_once_the_order_is_no_longer_paid(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->buyer)
            ->postJson("/api/orders/{$order->id}/confirm")
            ->assertOk();

        $this->actingAs($this->seller)
            ->postJson("/api/orders/{$order->id}/dispatch", ['tracking_number' => 'AB1'])
            ->assertStatus(422);
    }
}
