<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'location' => fake()->randomElement(['Newcastle', 'Leeds', 'Manchester', 'Bristol', 'Glasgow']),
            'bio' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A seller Stripe is willing to pay.
     *
     * Its own state rather than the default because "can be paid" is a real
     * milestone a seller has to reach, and a factory that handed it out for
     * free would hide every test of what happens before they do.
     */
    public function payoutReady(): static
    {
        return $this->state(fn (array $attributes) => [
            'stripe_account_id' => 'acct_'.fake()->unique()->bothify('??##########'),
            'stripe_charges_enabled' => true,
            'stripe_payouts_enabled' => true,
            'stripe_synced_at' => now(),
        ]);
    }

    /**
     * Started Stripe onboarding and did not finish it.
     *
     * The state most likely to be got wrong in production: there is an
     * account id, so a naive check says the seller is set up, and Stripe will
     * still refuse to move a penny.
     */
    public function payoutPending(): static
    {
        return $this->state(fn (array $attributes) => [
            'stripe_account_id' => 'acct_'.fake()->unique()->bothify('??##########'),
            'stripe_charges_enabled' => false,
            'stripe_payouts_enabled' => false,
            'stripe_synced_at' => now(),
        ]);
    }

    /**
     * community_verified is normally derived from endorsement count rather
     * than set - this state exists so tests that only care about how a
     * verified seller RENDERS don't have to stage two endorsers first.
     */
    public function communityVerified(): static
    {
        return $this->state(fn (array $attributes) => [
            'community_verified' => true,
        ]);
    }
}
