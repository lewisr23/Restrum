<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MessagingTest extends TestCase
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

    private function startConversation(string $content = 'Is this still available?'): Conversation
    {
        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/messages", ['content' => $content])
            ->assertCreated();

        return Conversation::sole();
    }

    public function test_messaging_a_listing_creates_the_conversation_and_its_first_message(): void
    {
        $response = $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/messages", [
                'content' => 'Is this still available?',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message.content', 'Is this still available?')
            ->assertJsonPath('conversation.listing.id', $this->listing->id);

        $this->assertDatabaseHas('conversations', [
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
        ]);
        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->buyer->id,
            'content' => 'Is this still available?',
            'message_type' => 'TEXT',
        ]);
    }

    public function test_messaging_the_same_listing_twice_continues_one_conversation(): void
    {
        $this->startConversation('First');

        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/messages", ['content' => 'Second'])
            ->assertCreated();

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, Message::count());
    }

    /**
     * Regression: new conversations on a SOLD listing used to be refused.
     * This marketplace takes no payment, so everything that actually
     * completes a sale is arranged in chat AFTER buying - that guard blocked
     * the buyer from the exact step checkout tells them to take.
     */
    public function test_a_buyer_can_start_a_conversation_about_a_listing_that_has_sold(): void
    {
        $this->listing->status = 'SOLD';
        $this->listing->save();

        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/messages", [
                'content' => 'Just bought this - when can I collect?',
            ])
            ->assertCreated()
            ->assertJsonPath('conversation.listing.status', 'SOLD');
    }

    public function test_a_seller_cannot_message_themselves_about_their_own_listing(): void
    {
        $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/messages", ['content' => 'Hello me'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('listing');
    }

    /**
     * Regression: this used to be written with update(['last_message_at' =>
     * ...]), which the model's mass-assignment guard silently discarded - so
     * the inbox, which orders by this column, stayed frozen at the time of
     * each thread's very first message.
     */
    public function test_replying_moves_the_conversation_to_the_top_of_the_inbox(): void
    {
        $conversation = $this->startConversation();
        $firstMessageTime = $conversation->fresh()->last_message_at;

        $this->travel(5)->minutes();

        $this->actingAs($this->seller)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Yes it is'])
            ->assertCreated();

        $this->assertTrue(
            $conversation->fresh()->last_message_at->greaterThan($firstMessageTime),
            'last_message_at should advance when a reply is sent',
        );
    }

    /**
     * Regression: created_at relied on the column's DB-level useCurrent()
     * default, so MySQL stamped it with the server's LOCAL time while
     * Laravel serialises stored timestamps as UTC - every message rendered
     * an hour out under British Summer Time.
     */
    public function test_a_messages_timestamp_comes_from_the_application_clock(): void
    {
        // Freezing the clock is what makes this independent of the machine's
        // timezone. Comparing created_at against now() would pass on a UTC
        // runner even with the bug present, because there MySQL's NOW() and
        // PHP's now() agree; a frozen app clock disagrees with the database's
        // wall clock everywhere, so only an app-written timestamp matches.
        $frozen = now()->setDate(2026, 1, 15)->setTime(12, 0, 0);
        $this->travelTo($frozen);

        $conversation = $this->startConversation();

        $this->assertEquals(
            $frozen->toDateTimeString(),
            $conversation->messages()->sole()->created_at->toDateTimeString(),
            'created_at should be written by the app, not left to the column default',
        );
    }

    public function test_only_the_buyer_can_make_a_price_offer(): void
    {
        $conversation = $this->startConversation();

        $this->actingAs($this->seller)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'content' => 'Offer', 'message_type' => 'PRICE_OFFER', 'offer_amount' => 400,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message_type');

        $this->actingAs($this->buyer)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'content' => 'Offer', 'message_type' => 'PRICE_OFFER', 'offer_amount' => 400,
            ])
            ->assertCreated()
            ->assertJsonPath('data.offer_status', 'PENDING');
    }

    public function test_an_offer_requires_an_amount(): void
    {
        $conversation = $this->startConversation();

        $this->actingAs($this->buyer)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'content' => 'Offer', 'message_type' => 'PRICE_OFFER',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('offer_amount');
    }

    public function test_accepting_an_offer_sells_the_listing_at_the_agreed_price(): void
    {
        $offer = $this->makePendingOffer(400);

        $this->actingAs($this->seller)
            ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.offer_status', 'ACCEPTED');

        $this->listing->refresh();
        $this->assertSame('SOLD', $this->listing->status);
        $this->assertEquals(400, $this->listing->price);
    }

    public function test_declining_an_offer_leaves_the_listing_on_sale_at_its_original_price(): void
    {
        $offer = $this->makePendingOffer(400);

        $this->actingAs($this->seller)
            ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'decline'])
            ->assertOk()
            ->assertJsonPath('data.offer_status', 'DECLINED');

        $this->listing->refresh();
        $this->assertSame('ACTIVE', $this->listing->status);
        $this->assertEquals(500, $this->listing->price);
    }

    public function test_only_the_seller_can_respond_to_an_offer(): void
    {
        $offer = $this->makePendingOffer(400);

        $this->actingAs($this->buyer)
            ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'accept'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');
    }

    public function test_an_offer_cannot_be_answered_twice(): void
    {
        $offer = $this->makePendingOffer(400);

        $this->actingAs($this->seller)
            ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'accept'])
            ->assertOk();

        $this->actingAs($this->seller)
            ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'decline'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');
    }

    public function test_opening_a_conversation_marks_the_other_persons_messages_as_read(): void
    {
        $conversation = $this->startConversation();

        // The buyer's own unread message must not count against them.
        $this->actingAs($this->buyer)->getJson('/api/conversations')
            ->assertOk()->assertJsonPath('data.0.unread_count', 0);

        $this->actingAs($this->seller)->getJson('/api/conversations')
            ->assertOk()->assertJsonPath('data.0.unread_count', 1);

        $this->actingAs($this->seller)->getJson("/api/conversations/{$conversation->id}")->assertOk();

        $this->actingAs($this->seller)->getJson('/api/conversations')
            ->assertOk()->assertJsonPath('data.0.unread_count', 0);
    }

    public function test_a_conversation_summary_describes_the_other_participant_from_each_side(): void
    {
        $this->startConversation();

        $this->actingAs($this->buyer)->getJson('/api/conversations')
            ->assertJsonPath('data.0.other_participant.username', $this->seller->username)
            ->assertJsonPath('data.0.am_i_seller', false);

        $this->actingAs($this->seller)->getJson('/api/conversations')
            ->assertJsonPath('data.0.other_participant.username', $this->buyer->username)
            ->assertJsonPath('data.0.am_i_seller', true);
    }

    public function test_the_inbox_preview_shows_the_most_recent_message(): void
    {
        $conversation = $this->startConversation('First message');

        $this->actingAs($this->seller)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Latest reply'])
            ->assertCreated();

        $this->actingAs($this->buyer)->getJson('/api/conversations')
            ->assertJsonPath('data.0.latest_message_preview', 'Latest reply');
    }

    public function test_someone_outside_the_conversation_cannot_read_or_post_to_it(): void
    {
        $conversation = $this->startConversation();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson("/api/conversations/{$conversation->id}")
            ->assertForbidden();

        $this->actingAs($stranger)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Butting in'])
            ->assertForbidden();
    }

    public function test_a_strangers_inbox_does_not_include_other_peoples_conversations(): void
    {
        $this->startConversation();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_sending_a_message_broadcasts_it(): void
    {
        Event::fake([MessageSent::class]);

        $this->startConversation();

        Event::assertDispatched(MessageSent::class);
    }

    /**
     * Answering an offer re-broadcasts the SAME message so both sides see the
     * status change live; the frontend relies on the id being unchanged to
     * update the existing bubble rather than append a second one.
     */
    public function test_answering_an_offer_rebroadcasts_that_same_message(): void
    {
        $offer = $this->makePendingOffer(400);

        Event::fake([MessageSent::class]);

        $this->actingAs($this->seller)
            ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'accept'])
            ->assertOk();

        Event::assertDispatched(
            MessageSent::class,
            fn (MessageSent $event) => $event->message->id === $offer->id
                && $event->message->offer_status === 'ACCEPTED',
        );
    }

    private function makePendingOffer(float $amount): Message
    {
        $conversation = $this->startConversation();

        $this->actingAs($this->buyer)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'content' => "Offer: £{$amount}",
                'message_type' => 'PRICE_OFFER',
                'offer_amount' => $amount,
            ])
            ->assertCreated();

        return Message::where('message_type', 'PRICE_OFFER')->sole();
    }
}
