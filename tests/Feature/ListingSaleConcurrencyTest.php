<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two endpoints can sell a listing: ListingController::buy and the accept
 * branch of MessageController::respond. Both used to read the listing's
 * status and then write it without holding the row, so two concurrent
 * requests could both see ACTIVE and both sell it, and a purchase racing an
 * accepted offer left the final price decided by whichever committed last.
 *
 * A genuine race cannot be reproduced in a single-threaded test run, so this
 * covers it from two directions instead: the sequential cross-path cases,
 * which were straightforwardly broken rather than merely racy, and an
 * assertion that each path actually takes the row lock that closes the
 * window. The second is a proxy, and is written as one on purpose - it fails
 * if someone later removes the locking, which is the regression worth
 * catching.
 */
class ListingSaleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create();
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

        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/buy")
            ->assertOk();

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

        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/buy")
            ->assertOk();

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

        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/buy")
            ->assertOk();

        $this->actingAs($other)
            ->postJson("/api/listings/{$this->listing->id}/buy")
            ->assertStatus(422)
            ->assertJsonValidationErrors('listing');
    }

    public function test_buying_locks_the_listing_row_before_selling_it(): void
    {
        $this->assertSelectsForUpdate(
            fn () => $this->actingAs($this->buyer)
                ->postJson("/api/listings/{$this->listing->id}/buy")
                ->assertOk(),
            'listings',
        );
    }

    public function test_accepting_an_offer_locks_the_listing_row_before_selling_it(): void
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
