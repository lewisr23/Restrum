<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithPayments;
use Tests\TestCase;

/**
 * Getting a private seller to the point where Stripe will pay them.
 *
 * The thing worth protecting here is that one user has exactly one connected
 * account. Stripe will create as many as it is asked to, only one of them can
 * be paid into, and a seller who ends up verified on the wrong one has done
 * all the work for nothing.
 */
class StripeConnectTest extends TestCase
{
    use InteractsWithPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePayments();
    }

    public function test_a_seller_who_has_not_started_is_told_so(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/stripe/connect')
            ->assertOk()
            ->assertJson([
                'onboarded' => false,
                'can_sell' => false,
            ]);
    }

    public function test_starting_onboarding_creates_an_account_and_returns_a_link(): void
    {
        $seller = User::factory()->create();

        $this->actingAs($seller)
            ->postJson('/api/stripe/connect')
            ->assertOk()
            ->assertJsonStructure(['url']);

        $seller->refresh();
        $this->assertNotNull($seller->stripe_account_id);

        // Not trading yet. The account exists, which is not the same thing,
        // and conflating the two is how a marketplace takes money for an
        // instrument whose seller can never be paid for it.
        $this->assertFalse($seller->canReceivePayments());
    }

    public function test_starting_again_reuses_the_same_account(): void
    {
        $seller = User::factory()->create();

        $this->actingAs($seller)->postJson('/api/stripe/connect')->assertOk();
        $accountId = $seller->fresh()->stripe_account_id;

        $this->actingAs($seller)->postJson('/api/stripe/connect')->assertOk();

        $this->assertSame($accountId, $seller->fresh()->stripe_account_id);
        $this->assertSame(1, $this->gateway->timesCalled('createConnectedAccount'));
        $this->assertSame(2, $this->gateway->timesCalled('createOnboardingLink'));
    }

    /**
     * The moment this exists for: the seller has just come back from Stripe
     * and the account.updated webhook has not landed yet, so the local answer
     * is correct and out of date at the same time.
     */
    public function test_a_refresh_pulls_the_current_state_from_stripe(): void
    {
        $seller = User::factory()->payoutPending()->create();

        $this->actingAs($seller)
            ->getJson('/api/stripe/connect')
            ->assertOk()
            ->assertJsonPath('can_sell', false);

        $this->actingAs($seller)
            ->getJson('/api/stripe/connect?refresh=1')
            ->assertOk()
            ->assertJsonPath('can_sell', true);

        $this->assertTrue($seller->fresh()->canReceivePayments());
    }

    /**
     * A stale mirror is better than an error page, because the webhook is
     * going to correct it anyway.
     */
    public function test_a_stripe_outage_during_refresh_falls_back_to_the_stored_state(): void
    {
        $seller = User::factory()->payoutReady()->create();

        $this->gateway->failWith = 'Stripe is down.';

        $this->actingAs($seller)
            ->getJson('/api/stripe/connect?refresh=1')
            ->assertOk()
            ->assertJsonPath('can_sell', true);
    }

    public function test_a_stripe_outage_while_starting_onboarding_is_reported_honestly(): void
    {
        $this->gateway->failWith = 'Stripe is down.';

        $this->actingAs(User::factory()->create())
            ->postJson('/api/stripe/connect')
            ->assertStatus(503);
    }

    /**
     * A connected account id identifies a real Stripe account. Nothing in the
     * interface needs it, and a marketplace has no reason to put one seller's
     * in front of another user.
     */
    public function test_a_connected_account_id_is_never_sent_to_other_users(): void
    {
        $seller = User::factory()->payoutReady()->create();

        $body = $this->getJson("/api/users/{$seller->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($seller->stripe_account_id, $body);
    }
}
