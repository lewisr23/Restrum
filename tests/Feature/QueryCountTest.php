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
 * Guards against N+1 queries on the endpoints that get hit most.
 *
 * The assertions count queries rather than measure time, because a timing
 * threshold is a flaky test on shared CI hardware while a query count is
 * deterministic. What actually breaks these pages under load is a query per
 * row, and that is exactly what a count catches.
 *
 * A failure here is not automatically a bug. It means the count moved, and
 * somebody should decide whether that was deliberate.
 */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    private function countQueries(callable $work): int
    {
        $count = 0;

        DB::listen(function () use (&$count) {
            $count++;
        });

        $work();

        return $count;
    }

    /**
     * Runs the request with one row, then with four, and fails if the query
     * count moved. Comparing two sizes rather than asserting a fixed number
     * means the test does not need updating every time an unrelated query
     * is legitimately added.
     */
    private function assertQueryCountDoesNotGrowWithRows(callable $request, callable $addRow): void
    {
        $addRow();

        // One untimed request first. The first request of a test pays for
        // things a later one does not, such as resolving the authenticated
        // user for the first time, and charging that to the one row case
        // makes a flat query count look like it went down.
        $request();

        $withOne = $this->countQueries($request);

        $addRow();
        $addRow();
        $addRow();
        $withFour = $this->countQueries($request);

        $this->assertSame(
            $withOne,
            $withFour,
            "Query count grew from {$withOne} to {$withFour} when rows went from 1 to 4, which is an N+1."
        );
    }

    /**
     * There are no factories for media, conversations or messages, and
     * adding them just for this file would be more surface than it earns.
     */
    private function listingWithMedia(?User $seller = null): Listing
    {
        $listing = $seller
            ? Listing::factory()->for($seller, 'seller')->create()
            : Listing::factory()->create();

        $listing->media()->create([
            'media_type' => 'IMAGE',
            'path' => "listings/{$listing->id}/photo.jpg",
        ]);

        return $listing;
    }

    private function conversationFor(User $viewer): Conversation
    {
        $seller = User::factory()->create();
        $listing = Listing::factory()->for($seller, 'seller')->create();

        $conversation = Conversation::create([
            'listing_id' => $listing->id,
            'buyer_id' => $viewer->id,
            'seller_id' => $seller->id,
        ]);

        $this->messageFrom($conversation, $seller);

        return $conversation;
    }

    private function messageFrom(Conversation $conversation, User $sender): Message
    {
        return $conversation->messages()->create([
            'sender_id' => $sender->id,
            'content' => 'Is this still available?',
            'message_type' => 'TEXT',
        ]);
    }

    public function test_browsing_listings_does_not_query_per_listing(): void
    {
        $this->assertQueryCountDoesNotGrowWithRows(
            fn () => $this->getJson('/api/listings')->assertOk(),
            fn () => $this->listingWithMedia(),
        );
    }

    public function test_browsing_as_a_signed_in_user_does_not_query_per_listing(): void
    {
        // The saved-by-viewer flag is the risky one here: it is a pivot
        // lookup, and those are easy to run per row by accident.
        $viewer = User::factory()->create();

        $this->assertQueryCountDoesNotGrowWithRows(
            fn () => $this->actingAs($viewer)->getJson('/api/listings')->assertOk(),
            fn () => $this->listingWithMedia(),
        );
    }

    public function test_the_saved_listings_page_does_not_query_per_listing(): void
    {
        $viewer = User::factory()->create();

        $this->assertQueryCountDoesNotGrowWithRows(
            fn () => $this->actingAs($viewer)->getJson('/api/listings/saved')->assertOk(),
            fn () => $viewer->savedListings()->attach($this->listingWithMedia()),
        );
    }

    public function test_a_seller_profile_does_not_query_per_listing(): void
    {
        $seller = User::factory()->create();

        $this->assertQueryCountDoesNotGrowWithRows(
            fn () => $this->getJson("/api/users/{$seller->id}")->assertOk(),
            fn () => $this->listingWithMedia($seller),
        );
    }

    public function test_the_inbox_does_not_query_per_conversation(): void
    {
        $viewer = User::factory()->create();

        $this->assertQueryCountDoesNotGrowWithRows(
            fn () => $this->actingAs($viewer)->getJson('/api/conversations')->assertOk(),
            fn () => $this->conversationFor($viewer),
        );
    }

    public function test_a_conversation_thread_does_not_query_per_message(): void
    {
        $viewer = User::factory()->create();
        $conversation = $this->conversationFor($viewer);
        $seller = $conversation->seller;

        $this->assertQueryCountDoesNotGrowWithRows(
            fn () => $this->actingAs($viewer)->getJson("/api/conversations/{$conversation->id}")->assertOk(),
            fn () => $this->messageFrom($conversation, $seller),
        );
    }
}
