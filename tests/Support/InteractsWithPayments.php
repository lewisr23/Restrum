<?php

namespace Tests\Support;

use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use Illuminate\Testing\TestResponse;

/**
 * The moves every payment test has to make: swap Stripe out, and pretend a
 * webhook arrived.
 *
 * Shared because buying something now takes two round trips rather than one -
 * a checkout that reserves, and a webhook that pays - and every test that
 * merely needs a sold listing would otherwise have to spell both out.
 */
trait InteractsWithPayments
{
    protected FakePaymentGateway $gateway;

    /**
     * Replace the real gateway for the rest of the test.
     *
     * Bound as an instance rather than a mock so assertions can be made about
     * what it was asked to do after the fact, which is usually the
     * interesting part: not that a payment was attempted, but for how much.
     */
    protected function fakePayments(): FakePaymentGateway
    {
        $this->gateway = new FakePaymentGateway;

        $this->app->instance(PaymentGateway::class, $this->gateway);

        return $this->gateway;
    }

    /** Start a checkout as this buyer and return the order it reserved. */
    protected function startCheckout(User $buyer, Listing $listing): Order
    {
        $response = $this->actingAs($buyer)
            ->postJson("/api/listings/{$listing->id}/checkout")
            ->assertSuccessful();

        return Order::findOrFail($response->json('order.id'));
    }

    /**
     * Drive the whole purchase: reserve, then have Stripe report the payment.
     *
     * What an actual sale looks like end to end, which is what most tests
     * that are not about payments actually want when they say "bought".
     */
    protected function buyOutright(User $buyer, Listing $listing): Order
    {
        $order = $this->startCheckout($buyer, $listing);

        $this->reportPayment($order)->assertOk();

        return $order->refresh();
    }

    /** The webhook Stripe sends when a payment goes through. */
    protected function reportPayment(Order $order): TestResponse
    {
        return $this->sendWebhook($this->stripeEvent('payment_intent.succeeded', [
            'id' => $order->stripe_payment_intent_id ?? "pi_test_{$order->id}",
            'metadata' => ['order_id' => (string) $order->id],
        ]));
    }

    /**
     * POST a webhook body the way Stripe would.
     *
     * The signature header is a literal because the fake gateway treats
     * exactly one value as invalid; the real signing algorithm is Stripe's
     * own library's job to get right, and re-implementing it here would only
     * test the re-implementation.
     */
    protected function sendWebhook(array $event, string $signature = 'valid'): TestResponse
    {
        return $this->postJson('/api/stripe/webhook', $event, [
            'Stripe-Signature' => $signature,
        ]);
    }

    /** @return array<string, mixed> */
    protected function stripeEvent(string $type, array $object): array
    {
        return [
            'id' => 'evt_test_'.bin2hex(random_bytes(6)),
            'type' => $type,
            'data' => ['object' => $object],
        ];
    }
}
