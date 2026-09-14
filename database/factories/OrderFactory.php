<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 40, 2000);

        return [
            'listing_id' => Listing::factory(),
            'buyer_id' => User::factory(),
            // Overridden by nearly every caller, since a realistic order has
            // the seller of its own listing rather than a third person.
            'seller_id' => User::factory()->payoutReady(),
            'amount' => $amount,
            'platform_fee' => round($amount * 0.05, 2),
            'currency' => 'GBP',
            'status' => OrderStatus::PENDING,
            'reserved_until' => now()->addMinutes(30),
        ];
    }

    /**
     * Consistent with itself: the buyer, the seller and the price all come
     * from one listing rather than three unrelated factories.
     *
     * Worth having because an order whose seller_id is not the listing's
     * seller is a state the application can never produce, and a test built
     * on one proves nothing about the application.
     */
    public function forListing(Listing $listing, User $buyer): static
    {
        return $this->state(fn (array $attributes) => [
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $listing->seller_id,
            'amount' => $listing->price,
            'platform_fee' => round(((float) $listing->price) * 0.05, 2),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::PAID,
            'reserved_until' => null,
            'paid_at' => now(),
            'stripe_payment_intent_id' => 'pi_test_'.fake()->unique()->bothify('??????????'),
        ]);
    }

    public function confirmed(): static
    {
        return $this->paid()->state(fn (array $attributes) => [
            'status' => OrderStatus::CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    /** A reservation that has run out, which is what the sweeper looks for. */
    public function lapsed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::PENDING,
            'reserved_until' => now()->subMinute(),
            'stripe_payment_intent_id' => 'pi_test_'.fake()->unique()->bothify('??????????'),
        ]);
    }
}
