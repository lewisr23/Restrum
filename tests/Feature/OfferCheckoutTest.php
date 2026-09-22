<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithPayments;
use Tests\TestCase;

/**
 * Haggling, and what an accepted offer actually buys you.
 *
 * Accepting an offer used to sell the listing outright: SOLD, price
 * overwritten, no order, no payment, no protection for either side. This
 * covers the replacement, where accepting grants the buyer a limited right
 * to check out at the agreed price and nothing else changes hands until they
 * do.
 *
 * The money assertions are the point of the file. An offer that is accepted
 * in the interface but not honoured by the card reader is worse than no
 * offers at all, and a discount that leaks to the wrong buyer is a way to
 * lose a seller their money.
 */
class OfferCheckoutTest extends TestCase
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
        $this->listing = Listing::factory()->for($this->seller, 'seller')->create([
            'price' => 500.00,
            'postage_price' => 10.00,
        ]);
    }

    /** An offer sent by this buyer and left unanswered. */
    private function offer(float $amount = 400, ?User $from = null): Message
    {
        $buyer = $from ?? $this->buyer;

        $this->actingAs($buyer)
            ->postJson("/api/listings/{$this->listing->id}/messages", ['content' => 'Interested'])
            ->assertCreated();

        $conversation = Conversation::where('buyer_id', $buyer->id)
            ->where('listing_id', $this->listing->id)
            ->sole();

        $this->actingAs($buyer)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'content' => "Offer: £{$amount}",
                'message_type' => 'PRICE_OFFER',
                'offer_amount' => $amount,
            ])
            ->assertCreated();

        return Message::where('conversation_id', $conversation->id)
            ->where('message_type', 'PRICE_OFFER')
            ->latest('id')
            ->sole();
    }

    /** An offer the seller has said yes to. */
    private function acceptedOffer(float $amount = 400, ?User $from = null): Message
    {
        $offer = $this->offer($amount, $from);

        $this->actingAs($this->seller)
            ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'accept'])
            ->assertOk();

        return $offer->fresh();
    }

    public function test_an_accepted_offer_is_what_the_buyer_is_charged(): void
    {
        $this->acceptedOffer(400);

        $order = $this->startCheckout($this->buyer, $this->listing);

        // 400 agreed for the item, plus the seller's carriage on top.
        $this->assertSame('410.00', (string) $order->amount);
        $this->assertSame('10.00', (string) $order->postage);

        // And Stripe is asked for that figure, not the listing's. This is the
        // assertion that would have caught an interface promising a discount
        // the payment never applied.
        $this->assertSame(41000, $this->gateway->firstCall('openPayment')['amount_pence']);
    }

    /**
     * An offer is on the item, not on the postage.
     *
     * Carriage is a cost the seller is recovering from a courier, so it is
     * not theirs to discount and not the buyer's to negotiate away by
     * offering a round number.
     */
    public function test_postage_is_charged_on_top_of_the_agreed_price(): void
    {
        $this->listing->postage_price = 25.00;
        $this->listing->save();

        $this->acceptedOffer(400);

        $this->assertSame('425.00', (string) $this->startCheckout($this->buyer, $this->listing)->amount);
    }

    /**
     * The platform's cut follows the price actually paid.
     *
     * Charging 5% of the asking price on a sale that happened below it would
     * be the marketplace taking a fee on money nobody received.
     */
    public function test_the_platform_fee_is_taken_on_the_agreed_price(): void
    {
        $this->acceptedOffer(400);

        $order = $this->startCheckout($this->buyer, $this->listing);

        // 5% of 400, not of 500, and not of the 410 total.
        $this->assertSame('20.00', (string) $order->platform_fee);
        $this->assertSame('390.00', $order->sellerProceeds());
    }

    public function test_the_order_records_which_offer_it_was_struck_at(): void
    {
        $offer = $this->acceptedOffer(400);

        $order = $this->startCheckout($this->buyer, $this->listing);

        $this->assertSame($offer->id, $order->offer_id);
        $this->assertEquals(400, $order->offer->offer_amount);
    }

    public function test_a_purchase_with_no_offer_behind_it_records_none(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);

        $this->assertNull($order->offer_id);
        $this->assertSame('510.00', (string) $order->amount);
    }

    public function test_an_offer_the_seller_has_not_answered_discounts_nothing(): void
    {
        $this->offer(400);

        $this->assertSame('510.00', (string) $this->startCheckout($this->buyer, $this->listing)->amount);
    }

    public function test_a_declined_offer_discounts_nothing(): void
    {
        $offer = $this->offer(400);

        $this->actingAs($this->seller)
            ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'decline'])
            ->assertOk();

        $this->assertSame('510.00', (string) $this->startCheckout($this->buyer, $this->listing)->amount);
    }

    /**
     * The deadline is real, and it is read at the moment of payment.
     *
     * Nothing sweeps expired offers, so if this is ever checked anywhere but
     * here, an offer accepted last spring is still claimable today.
     */
    public function test_an_expired_offer_falls_back_to_the_asking_price(): void
    {
        config(['services.stripe.offer_hours' => 48]);

        $this->acceptedOffer(400);

        $this->travel(49)->hours();

        $this->assertSame('510.00', (string) $this->startCheckout($this->buyer, $this->listing)->amount);
    }

    public function test_an_offer_still_inside_its_window_is_honoured(): void
    {
        config(['services.stripe.offer_hours' => 48]);

        $this->acceptedOffer(400);

        $this->travel(47)->hours();

        $this->assertSame('410.00', (string) $this->startCheckout($this->buyer, $this->listing)->amount);
    }

    /** A price agreed with one person is not a sale price for everyone. */
    public function test_another_buyer_does_not_get_the_agreed_price(): void
    {
        $this->acceptedOffer(400);

        $other = User::factory()->create();

        $this->assertSame('510.00', (string) $this->startCheckout($other, $this->listing)->amount);
    }

    /**
     * Offers are scoped to the listing they were made about.
     *
     * The lookup keys off the conversation rather than the buyer alone, and
     * a conversation belongs to one listing. Without that, haggling over a
     * cheap pedal would quietly reprice the same seller's guitar.
     */
    public function test_an_offer_on_one_listing_does_not_price_another(): void
    {
        $this->acceptedOffer(400);

        $other = Listing::factory()->for($this->seller, 'seller')->create([
            'price' => 900.00,
            'postage_price' => 0.00,
        ]);

        $this->assertSame('900.00', (string) $this->startCheckout($this->buyer, $other)->amount);
    }

    /**
     * The listing stays on sale for the whole window.
     *
     * This is the behaviour change a seller will notice, and it is the
     * deliberate one: agreeing a price with someone who then never pays used
     * to cost the seller the listing.
     */
    public function test_the_listing_stays_on_sale_until_someone_actually_pays(): void
    {
        $this->acceptedOffer(400);

        $this->listing->refresh();
        $this->assertSame('ACTIVE', $this->listing->status);
        $this->assertEquals(500, $this->listing->price);

        // And it is still findable, which is what "on sale" means to anyone
        // who is not reading the database.
        $this->getJson('/api/listings')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->listing->id]);
    }

    /**
     * Whoever pays first gets it, offer or no offer.
     *
     * The alternative - reserving the item for the buyer whose offer was
     * accepted - would hand any buyer a way to take an instrument off the
     * market for two days by haggling, which is a worse hole than the one
     * this flow replaced.
     */
    public function test_an_accepted_offer_does_not_reserve_the_listing(): void
    {
        $this->acceptedOffer(400);

        $other = User::factory()->create();
        $this->buyOutright($other, $this->listing);

        $this->assertSame('SOLD', $this->listing->fresh()->status);

        // The agreed price is now worth nothing, and says so rather than
        // failing somewhere inside Stripe.
        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/checkout")
            ->assertStatus(422)
            ->assertJsonValidationErrors('listing');
    }

    public function test_paying_at_the_agreed_price_completes_the_sale(): void
    {
        $this->acceptedOffer(400);

        $order = $this->buyOutright($this->buyer, $this->listing);

        $this->assertSame('PAID', $order->status->value);
        $this->assertSame('410.00', (string) $order->amount);
        $this->assertSame('SOLD', $this->listing->fresh()->status);

        // The asking price survives the sale. It used to be overwritten at
        // the moment of acceptance, so the listing's own history of what it
        // was offered at was lost.
        $this->assertEquals(500, $this->listing->fresh()->price);
    }

    public function test_the_listing_page_tells_the_buyer_what_they_agreed(): void
    {
        $this->acceptedOffer(400);

        $this->actingAs($this->buyer)
            ->getJson("/api/listings/{$this->listing->id}")
            ->assertOk()
            ->assertJsonPath('your_offer.amount', '400.00');
    }

    public function test_the_listing_page_offers_nobody_else_that_price(): void
    {
        $this->acceptedOffer(400);

        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson("/api/listings/{$this->listing->id}")
            ->assertOk()
            ->assertJsonPath('your_offer', null);

        // Including the seller, who would otherwise be shown a discount on
        // their own listing.
        $this->actingAs($this->seller)
            ->getJson("/api/listings/{$this->listing->id}")
            ->assertOk()
            ->assertJsonPath('your_offer', null);

        $this->getJson("/api/listings/{$this->listing->id}")
            ->assertOk()
            ->assertJsonPath('your_offer', null);
    }

    /**
     * The checkout response carries the figures the page prints.
     *
     * The page used to itemise the listing's price and postage beside a
     * total taken from the order, which only agreed because the two could
     * not differ. They can now.
     */
    public function test_the_checkout_response_itemises_the_price_actually_charged(): void
    {
        $this->acceptedOffer(400);

        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/checkout")
            ->assertSuccessful()
            ->assertJsonPath('order.item_price', '400.00')
            ->assertJsonPath('order.postage', '10.00')
            ->assertJsonPath('order.amount', '410.00')
            ->assertJsonPath('order.agreed_offer.amount', '400.00');
    }
}
