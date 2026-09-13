<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * The transition whitelist is the rule that stops a redelivered or
 * out-of-order Stripe webhook moving money twice, so it is worth testing
 * directly rather than only through the endpoints that lean on it.
 */
class OrderStatusTest extends TestCase
{
    public function test_the_happy_path_runs_pending_to_released(): void
    {
        $this->assertTrue(OrderStatus::PENDING->canTransitionTo(OrderStatus::PAID));
        $this->assertTrue(OrderStatus::PAID->canTransitionTo(OrderStatus::CONFIRMED));
        $this->assertTrue(OrderStatus::CONFIRMED->canTransitionTo(OrderStatus::RELEASED));
    }

    public function test_payment_cannot_be_applied_twice(): void
    {
        // The redelivery case: Stripe sends payment_intent.succeeded again for
        // an order that has already moved on. Re-entering PAID must be refused
        // rather than re-stamping paid_at or re-running any side effect.
        $this->assertFalse(OrderStatus::PAID->canTransitionTo(OrderStatus::PAID));
        $this->assertFalse(OrderStatus::CONFIRMED->canTransitionTo(OrderStatus::PAID));
        $this->assertFalse(OrderStatus::RELEASED->canTransitionTo(OrderStatus::PAID));
    }

    public function test_money_cannot_be_released_before_it_is_taken(): void
    {
        $this->assertFalse(OrderStatus::PENDING->canTransitionTo(OrderStatus::RELEASED));
        $this->assertFalse(OrderStatus::PENDING->canTransitionTo(OrderStatus::CONFIRMED));
        $this->assertFalse(OrderStatus::PENDING->canTransitionTo(OrderStatus::REFUNDED));
    }

    public function test_terminal_states_go_nowhere(): void
    {
        $this->assertTrue(OrderStatus::REFUNDED->isTerminal());
        $this->assertTrue(OrderStatus::CANCELLED->isTerminal());

        foreach (OrderStatus::cases() as $case) {
            $this->assertFalse(
                OrderStatus::REFUNDED->canTransitionTo($case),
                "A refunded order must not move to {$case->value}.",
            );
        }
    }

    public function test_a_chargeback_is_reachable_even_after_payout(): void
    {
        // RELEASED is not terminal on purpose: a card dispute can land after
        // the seller has been paid, and the model should be able to say so.
        $this->assertFalse(OrderStatus::RELEASED->isTerminal());
        $this->assertTrue(OrderStatus::RELEASED->canTransitionTo(OrderStatus::DISPUTED));
    }

    public function test_only_states_that_hold_money_block_a_listing(): void
    {
        $this->assertTrue(OrderStatus::PAID->holdsFunds());
        $this->assertTrue(OrderStatus::CONFIRMED->holdsFunds());
        $this->assertTrue(OrderStatus::DISPUTED->holdsFunds());

        // An abandoned checkout took no money and must not keep an instrument
        // off the market.
        $this->assertFalse(OrderStatus::PENDING->holdsFunds());
        $this->assertFalse(OrderStatus::CANCELLED->holdsFunds());
        $this->assertFalse(OrderStatus::REFUNDED->holdsFunds());

        // Once the seller has the money the sale is over, so the order no
        // longer needs to hold the listing: the listing itself is SOLD.
        $this->assertFalse(OrderStatus::RELEASED->holdsFunds());
    }
}
