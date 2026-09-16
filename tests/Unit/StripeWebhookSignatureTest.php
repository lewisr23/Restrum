<?php

namespace Tests\Unit;

use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\StripePaymentGateway;
use PHPUnit\Framework\TestCase;

/**
 * Webhook signature checking, against the real gateway rather than the fake.
 *
 * Stripe cannot send v1 and v2 events to one destination: the first are
 * "snapshot" payloads, the second "thin" ones. So this integration registers
 * two destinations on the same URL and Stripe signs each with its own
 * secret. Everything here exists to pin that down, because the failure it
 * prevents is silent: seller onboarding events rejected as forgeries, and
 * sellers who finish verification but are never marked able to trade.
 */
class StripeWebhookSignatureTest extends TestCase
{
    private const FIRST = 'whsec_first_destination_secret';

    private const SECOND = 'whsec_second_destination_secret';

    private function gateway(?string $secrets): StripePaymentGateway
    {
        return new StripePaymentGateway('sk_test_unused', $secrets);
    }

    /** Sign a payload the way Stripe does. */
    private function sign(string $payload, string $secret): string
    {
        $t = time();

        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", $secret);
    }

    public function test_a_payload_signed_by_the_first_destination_is_accepted(): void
    {
        $payload = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded']);

        $event = $this->gateway(self::FIRST.','.self::SECOND)
            ->parseWebhook($payload, $this->sign($payload, self::FIRST));

        $this->assertSame('payment_intent.succeeded', $event['type']);
    }

    /**
     * The one that matters. Before multi-secret support this threw, which on
     * a live site meant Connect onboarding never completed.
     */
    public function test_a_payload_signed_by_the_second_destination_is_accepted(): void
    {
        $payload = json_encode([
            'id' => 'evt_2',
            'type' => 'v2.core.account[configuration.recipient].capability_status_updated',
            'related_object' => ['id' => 'acct_123'],
        ]);

        $event = $this->gateway(self::FIRST.','.self::SECOND)
            ->parseWebhook($payload, $this->sign($payload, self::SECOND));

        $this->assertSame('acct_123', $event['related_object']['id']);
    }

    public function test_whitespace_around_the_secrets_is_tolerated(): void
    {
        $payload = json_encode(['id' => 'evt_3', 'type' => 'charge.refunded']);

        $event = $this->gateway(' '.self::FIRST.' , '.self::SECOND.' ')
            ->parseWebhook($payload, $this->sign($payload, self::SECOND));

        $this->assertSame('charge.refunded', $event['type']);
    }

    public function test_a_payload_signed_by_nobody_we_know_is_rejected(): void
    {
        $payload = json_encode(['id' => 'evt_4', 'type' => 'payment_intent.succeeded']);

        $this->expectException(PaymentGatewayException::class);

        $this->gateway(self::FIRST.','.self::SECOND)
            ->parseWebhook($payload, $this->sign($payload, 'whsec_someone_elses_secret'));
    }

    /**
     * An unconfigured secret has to close the endpoint rather than open it.
     */
    public function test_an_unconfigured_secret_rejects_everything(): void
    {
        $payload = json_encode(['id' => 'evt_5', 'type' => 'payment_intent.succeeded']);

        $this->expectException(PaymentGatewayException::class);

        $this->gateway('')->parseWebhook($payload, $this->sign($payload, self::FIRST));
    }
}
