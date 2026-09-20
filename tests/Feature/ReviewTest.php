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
 * Feedback, and the gate that makes it mean something.
 *
 * Endorsements only require a conversation, so two accounts can talk to each
 * other and endorse each other into a reputation nobody earned. A review
 * requires a completed order, which means real money moved through Stripe.
 * These tests are mostly about who is refused.
 */
class ReviewTest extends TestCase
{
    use InteractsWithPayments, RefreshDatabase;

    private User $buyer;

    private User $seller;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePayments();

        $this->buyer = User::factory()->create();
        $this->seller = User::factory()->payoutReady()->create();
        $this->listing = Listing::factory()->for($this->seller, 'seller')->create();
    }

    private function completedOrder(): Order
    {
        $order = $this->buyOutright($this->buyer, $this->listing->fresh());
        $order->status = OrderStatus::CONFIRMED;
        $order->save();

        return $order->fresh();
    }

    public function test_a_buyer_can_review_the_seller_after_a_completed_order(): void
    {
        $order = $this->completedOrder();

        $this->actingAs($this->buyer)
            ->postJson("/api/orders/{$order->id}/review", ['rating' => 5, 'comment' => 'Exactly as described.'])
            ->assertCreated()
            ->assertJsonPath('data.reviewer_role', 'BUYER');

        $this->assertDatabaseHas('reviews', [
            'order_id' => $order->id,
            'reviewer_id' => $this->buyer->id,
            'subject_id' => $this->seller->id,
            'rating' => 5,
        ]);
    }

    public function test_a_seller_can_review_the_buyer(): void
    {
        $order = $this->completedOrder();

        $this->actingAs($this->seller)
            ->postJson("/api/orders/{$order->id}/review", ['rating' => 4])
            ->assertCreated()
            ->assertJsonPath('data.reviewer_role', 'SELLER');

        $this->assertDatabaseHas('reviews', [
            'reviewer_id' => $this->seller->id,
            'subject_id' => $this->buyer->id,
        ]);
    }

    /** The whole point: no purchase, no voice. */
    public function test_a_stranger_cannot_review(): void
    {
        $order = $this->completedOrder();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/orders/{$order->id}/review", ['rating' => 1])
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    /**
     * A review before the gear has arrived is a review of nothing.
     */
    public function test_an_order_that_is_only_paid_cannot_be_reviewed_yet(): void
    {
        $order = $this->buyOutright($this->buyer, $this->listing->fresh());

        $this->actingAs($this->buyer)
            ->postJson("/api/orders/{$order->id}/review", ['rating' => 5])
            ->assertStatus(422);
    }

    /** Otherwise a grudge is an unlimited supply of one-star ratings. */
    public function test_the_same_person_cannot_review_an_order_twice(): void
    {
        $order = $this->completedOrder();

        $this->actingAs($this->buyer)
            ->postJson("/api/orders/{$order->id}/review", ['rating' => 5])
            ->assertCreated();

        $this->actingAs($this->buyer)
            ->postJson("/api/orders/{$order->id}/review", ['rating' => 1])
            ->assertStatus(422);

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_ratings_outside_one_to_five_are_refused(): void
    {
        $order = $this->completedOrder();

        foreach ([0, 6, -1] as $rating) {
            $this->actingAs($this->buyer)
                ->postJson("/api/orders/{$order->id}/review", ['rating' => $rating])
                ->assertStatus(422);
        }
    }

    public function test_a_profile_reports_the_average_and_the_reviews(): void
    {
        $order = $this->completedOrder();

        $this->actingAs($this->buyer)
            ->postJson("/api/orders/{$order->id}/review", ['rating' => 4, 'comment' => 'Well packed.'])
            ->assertCreated();

        $response = $this->getJson("/api/users/{$this->seller->id}")->assertOk();

        // assertEquals, not assertSame: JSON has no way to distinguish 4.0
        // from 4, so the type that comes back is not the resource's business.
        $this->assertEquals(4.0, $response->json('data.rating_average'));
        $this->assertSame(1, $response->json('data.rating_count'));
        $this->assertSame('Well packed.', $response->json('data.reviews.0.comment'));
    }

    public function test_a_profile_with_no_reviews_reports_no_average(): void
    {
        $response = $this->getJson("/api/users/{$this->seller->id}")->assertOk();

        $this->assertNull($response->json('data.rating_average'));
        $this->assertSame(0, $response->json('data.rating_count'));
    }
}
