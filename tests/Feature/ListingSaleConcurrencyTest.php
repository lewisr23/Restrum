<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithPayments;
use Tests\TestCase;

/**
 * Selling a listing goes through checkout, and only through checkout. It used
 * to be two paths - a checkout, and the accept branch of
 * MessageController::respond - and both read the listing's status and then
 * wrote it without holding the row, so two concurrent requests could both see
 * ACTIVE and both sell it, and a purchase racing an accepted offer left the
 * final price decided by whichever committed last.
 *
 * Accepting an offer no longer sells anything, which removes the cross-path
 * race rather than fixing it. What is left to protect is that accepting on a
 * listing already sold is still refused, and that both paths still read the
 * status under a lock: the accept branch decides on that status even though
 * it no longer writes it.
 *
 * A genuine race cannot be reproduced in a single-threaded test run, so this
 * covers it from two directions instead: the sequential cross-path cases,
 * which were straightforwardly broken rather than merely racy, and an
 * assertion that each path actually takes the row lock that closes the
 * window. The second is a proxy, and is written as one on purpose - it fails
 * if someone later removes the locking, which is the regression worth
 * catching.
 *
 * Payment moved the boundary rather than removing it. A checkout now reserves
 * the listing before it sells it, so there are two moments to protect instead
 * of one, and the reservation is the earlier and busier of the two.
 */
class ListingSaleConcurrencyTest extends TestCase
{
    use InteractsWithPayments, RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePayments();

        // payoutReady because a seller Stripe will not pay cannot be bought
        // from at all, which is its own test rather than a precondition of
        // every test in this file.
        $this->seller = User::factory()->payoutReady()->create();
        $this->buyer = User::factory()->create();
        $this->listing = Listing::factory()->for($this->seller, 'seller')->create(['price' => 500]);
    }

    private function makePendingOffer(float $amount): Message
    {
        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/messages", ['content' => 'Interested'])
            ->assertCreated();

        $conversation = Conversation::sole();

        $this->actingAs($this->buyer)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'content' => "Offer: £{$amount}",
                'message_type' => 'PRICE_OFFER',
                'offer_amount' => $amount,
            ])
            ->assertCreated();

        return Message::where('message_type', 'PRICE_OFFER')->sole();
    }

    public function test_an_offer_cannot_be_accepted_once_the_listing_has_been_bought(): void
    {
        $offer = $this->makePendingOffer(400);

        $this->buyOutright($this->buyer, $this->listing);

        $this->actingAs($this->seller)
            ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'accept'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        // The sale price must survive. Before the fix the accept went through
        // and rewrote a listing already sold at 500 down to the 400 offer.
        $this->listing->refresh();
        $this->assertSame('SOLD', $this->listing->status);
        $this->assertEquals(500, $this->listing->price);
        $this->assertSame('PENDING', $offer->fresh()->offer_status);
    }

    public function test_an_offer_on_a_sold_listing_can_still_be_declined(): void
    {
        $offer = $this->makePendingOffer(400);

        $this->buyOutright($this->buyer, $this->listing);

        // Declining changes nothing about the sale, so tidying up a dead
        // offer stays allowed where accepting one does not.
        $this->actingAs($this->seller)
            ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'decline'])
            ->assertOk()
            ->assertJsonPath('data.offer_status', 'DECLINED');

        $this->listing->refresh();
        $this->assertSame('SOLD', $this->listing->status);
        $this->assertEquals(500, $this->listing->price);
    }

    public function test_a_second_purchase_of_the_same_listing_is_rejected(): void
    {
        $other = User::factory()->create();

        $this->buyOutright($this->buyer, $this->listing);

        $this->actingAs($other)
            ->postJson("/api/listings/{$this->listing->id}/checkout")
            ->assertStatus(422)
            ->assertJsonValidationErrors('listing');
    }

    /**
     * The case the reservation exists for, and the one the old buy endpoint
     * could not have had: money has not moved yet, the listing is still
     * ACTIVE, and a second buyer must still be turned away.
     *
     * Without this they both reach Stripe, both pay, and one of them is
     * refunded a guitar they were told they had bought.
     */
    public function test_a_listing_someone_is_checking_out_cannot_be_checked_out_again(): void
    {
        $other = User::factory()->create();

        $this->startCheckout($this->buyer, $this->listing);

        // Still for sale as far as the listing itself is concerned.
        $this->assertSame('ACTIVE', $this->listing->fresh()->status);

        $this->actingAs($other)
            ->postJson("/api/listings/{$this->listing->id}/checkout")
            ->assertStatus(422)
            ->assertJsonValidationErrors('listing');

        // And only one session was ever opened, so there is only one way to
        // pay for it in existence.
        $this->assertSame(1, $this->gateway->timesCalled('openPayment'));
    }

    public function test_reserving_a_listing_locks_its_row_first(): void
    {
        $this->assertSelectsForUpdate(
            fn () => $this->actingAs($this->buyer)
                ->postJson("/api/listings/{$this->listing->id}/checkout")
                ->assertSuccessful(),
            'listings',
        );
    }

    public function test_accepting_an_offer_locks_the_listing_row_before_reading_its_status(): void
    {
        $offer = $this->makePendingOffer(400);

        $this->assertSelectsForUpdate(
            fn () => $this->actingAs($this->seller)
                ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'accept'])
                ->assertOk(),
            'listings',
        );
    }

    /**
     * Asserts the given request issued a `select ... for update` against the
     * named table. Reading the query log rather than timing two connections
     * against each other keeps this deterministic, in the same spirit as
     * QueryCountTest counting queries instead of measuring milliseconds.
     */
    private function assertSelectsForUpdate(callable $request, string $table): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $request();

        $locking = array_filter(
            DB::getQueryLog(),
            fn (array $query) => str_contains(strtolower($query['query']), 'for update')
                && str_contains(strtolower($query['query']), '`'.$table.'`'),
        );

        DB::disableQueryLog();

        $this->assertNotEmpty(
            $locking,
            "Expected a `select ... for update` on `{$table}`, so the status check and the "
            .'write that depends on it cannot be split by a concurrent request. None was issued.',
        );
    }
}
