<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\User;
use App\Services\Payments\AccountState;
use App\Services\Payments\CheckoutHandle;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayException;

/**
 * Stripe, as far as the tests are concerned.
 *
 * It records what it was asked to do and can be told to fail, which between
 * them cover the two things worth asserting about a payment integration: that
 * the right call was made with the right amount, and that the application
 * does something sensible when the call does not work.
 *
 * It deliberately does NOT simulate Stripe. There is no state machine in here
 * pretending to be a Checkout Session, because a fake that models the vendor
 * ends up being trusted for things only the vendor can actually tell you.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** Session ids this gateway was asked to expire. */
    public array $abandoned = [];

    /** Set to false to make abandonCheckout report the buyer already paid. */
    public bool $abandonSucceeds = true;

    /** Set to throw from every method, standing in for Stripe being down. */
    public ?string $failWith = null;

    public AccountState $accountState;

    private int $counter = 0;

    public function __construct()
    {
        $this->accountState = new AccountState(
            chargesEnabled: true,
            payoutsEnabled: true,
            detailsSubmitted: true,
        );
    }

    public function createConnectedAccount(User $seller): string
    {
        $this->guard();
        $this->record('createConnectedAccount', ['user_id' => $seller->id]);

        return 'acct_fake'.(++$this->counter);
    }

    public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        $this->guard();
        $this->record('createOnboardingLink', [
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
        ]);

        return "https://connect.stripe.test/onboard/{$accountId}";
    }

    public function fetchAccountState(string $accountId): AccountState
    {
        $this->guard();
        $this->record('fetchAccountState', ['account' => $accountId]);

        return $this->accountState;
    }

    public function openCheckout(Order $order, string $successUrl, string $cancelUrl): CheckoutHandle
    {
        $this->guard();

        // The amount is recorded in pence, exactly as the real gateway would
        // send it, because rounding a price into minor units is precisely the
        // kind of thing that is right until it is a penny wrong.
        $this->record('openCheckout', [
            'order_id' => $order->id,
            'amount_pence' => $order->amountInPence(),
            'currency' => $order->currency,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        $id = 'cs_test_fake'.(++$this->counter);

        return new CheckoutHandle($id, "https://checkout.stripe.test/{$id}");
    }

    public function abandonCheckout(string $sessionId): bool
    {
        $this->guard();
        $this->record('abandonCheckout', ['session' => $sessionId]);
        $this->abandoned[] = $sessionId;

        return $this->abandonSucceeds;
    }

    public function payOutSeller(Order $order, string $idempotencyKey): string
    {
        $this->guard();
        $this->record('payOutSeller', [
            'order_id' => $order->id,
            'amount_pence' => $order->sellerProceedsInPence(),
            'destination' => $order->seller->stripe_account_id,
            'idempotency_key' => $idempotencyKey,
        ]);

        return 'tr_fake'.(++$this->counter);
    }

    public function refundBuyer(Order $order, string $idempotencyKey): string
    {
        $this->guard();
        $this->record('refundBuyer', [
            'order_id' => $order->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        return 're_fake'.(++$this->counter);
    }

    public function parseWebhook(string $payload, string $signature): array
    {
        $this->guard();

        // The signature is checked by Stripe's own library, which is tested by
        // Stripe. What matters here is that an invalid one is refused, so the
        // fake treats one magic value as invalid and everything else as fine.
        if ($signature === 'invalid') {
            throw new PaymentGatewayException('Stripe webhook signature is invalid.');
        }

        return json_decode($payload, true) ?? [];
    }

    /** How many times a given method was called. */
    public function timesCalled(string $method): int
    {
        return count(array_filter($this->calls, fn (array $c) => $c['method'] === $method));
    }

    /** The arguments of the first call to a method, or null if never called. */
    public function firstCall(string $method): ?array
    {
        foreach ($this->calls as $call) {
            if ($call['method'] === $method) {
                return $call['arguments'];
            }
        }

        return null;
    }

    private function record(string $method, array $arguments): void
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];
    }

    private function guard(): void
    {
        if ($this->failWith !== null) {
            throw new PaymentGatewayException($this->failWith);
        }
    }
}
