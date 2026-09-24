<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Notifications\GearSold;
use App\Notifications\MessageReceived;
use App\Notifications\OfferAnswered;
use App\Notifications\OfferReceived;
use App\Notifications\OrderDispatched;
use App\Notifications\OrderRefunded;
use App\Notifications\PaymentHeld;
use App\Notifications\PayoutSent;
use App\Services\Payments\EscrowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithPayments;
use Tests\TestCase;

/**
 * Telling people what happened.
 *
 * The site used to tell nobody anything: a seller learned they had sold
 * something by opening the page and looking. That is worse than an
 * inconvenience next to the escrow clock, which releases a buyer's money
 * after a fortnight whether or not they ever heard the parcel had been sent.
 *
 * Two things are worth testing about a notification and the rest is
 * decoration: that it reaches the right person and nobody else, and that
 * being told the same thing twice does not send it twice. Stripe redelivers
 * webhooks as a matter of course, so the second is not hypothetical.
 */
class NotificationTest extends TestCase
{
    use InteractsWithPayments, RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePayments();
        Notification::fake();

        $this->seller = User::factory()->payoutReady()->create();
        $this->buyer = User::factory()->create();
        $this->listing = Listing::factory()->for($this->seller, 'seller')->create([
            'price' => 500.00,
            'postage_price' => 10.00,
        ]);
    }

    public function test_a_sale_tells_both_sides(): void
    {
        $this->buyOutright($this->buyer, $this->listing);

        Notification::assertSentTo($this->seller, GearSold::class);
        Notification::assertSentTo($this->buyer, PaymentHeld::class);

        // And not the other way round, which would tell a seller their money
        // is being held from them.
        Notification::assertNotSentTo($this->buyer, GearSold::class);
        Notification::assertNotSentTo($this->seller, PaymentHeld::class);
    }

    /**
     * Stripe retries deliveries, and the correct response to being told
     * something twice is to do it once.
     */
    public function test_a_redelivered_payment_webhook_does_not_notify_twice(): void
    {
        $order = $this->startCheckout($this->buyer, $this->listing);

        $this->reportPayment($order)->assertOk();
        $this->reportPayment($order)->assertOk();

        Notification::assertSentToTimes($this->seller, GearSold::class, 1);
        Notification::assertSentToTimes($this->buyer, PaymentHeld::class, 1);
    }

    public function test_dispatching_tells_the_buyer(): void
    {
        $order = $this->buyOutright($this->buyer, $this->listing);

        app(EscrowService::class)->markDispatched($order, 'Royal Mail', 'AB123456789GB');

        Notification::assertSentTo($this->buyer, OrderDispatched::class);
        Notification::assertNotSentTo($this->seller, OrderDispatched::class);
    }

    public function test_being_paid_out_tells_the_seller(): void
    {
        $order = $this->buyOutright($this->buyer, $this->listing);
        $escrow = app(EscrowService::class);

        $escrow->markDispatched($order, 'Royal Mail', 'AB123456789GB');
        $escrow->confirmReceipt($order->refresh(), $this->buyer);
        $escrow->release($order->refresh());

        Notification::assertSentTo($this->seller, PayoutSent::class);
    }

    public function test_a_refund_tells_the_buyer(): void
    {
        $order = $this->buyOutright($this->buyer, $this->listing);

        app(EscrowService::class)->refund($order);

        Notification::assertSentTo($this->buyer, OrderRefunded::class);
    }

    public function test_a_message_reaches_the_other_person_only(): void
    {
        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/messages", ['content' => 'Still available?'])
            ->assertCreated();

        Notification::assertSentTo($this->seller, MessageReceived::class);
        Notification::assertNotSentTo($this->buyer, MessageReceived::class);
    }

    /**
     * Chat is the one thing here that does not email.
     *
     * A dozen emails during one ordinary conversation about whether a pedal
     * has its box is how someone learns to filter everything this site
     * sends, including the message that says their money moved.
     */
    public function test_an_ordinary_message_does_not_email(): void
    {
        $this->seller->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/messages", ['content' => 'Still available?'])
            ->assertCreated();

        Notification::assertSentTo(
            $this->seller,
            MessageReceived::class,
            fn (MessageReceived $notification, array $channels) => $channels === ['database'],
        );
    }

    /**
     * An unverified address is one nobody has proved reaches a person, so
     * sending someone's sale details to it is at best pointless.
     */
    public function test_nothing_is_emailed_to_an_unconfirmed_address(): void
    {
        $this->seller->forceFill(['email_verified_at' => null])->save();

        $this->buyOutright($this->buyer, $this->listing);

        Notification::assertSentTo(
            $this->seller,
            GearSold::class,
            fn (GearSold $notification, array $channels) => $channels === ['database'],
        );
    }

    public function test_a_confirmed_address_gets_the_email_as_well(): void
    {
        $this->seller->forceFill(['email_verified_at' => now()])->save();

        $this->buyOutright($this->buyer, $this->listing);

        Notification::assertSentTo(
            $this->seller,
            GearSold::class,
            fn (GearSold $notification, array $channels) => in_array('mail', $channels, true),
        );
    }

    public function test_an_offer_and_its_answer_reach_the_right_people(): void
    {
        $this->actingAs($this->buyer)
            ->postJson("/api/listings/{$this->listing->id}/messages", ['content' => 'Interested'])
            ->assertCreated();

        $conversation = Conversation::sole();

        $this->actingAs($this->buyer)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'content' => 'Offer: £400',
                'message_type' => 'PRICE_OFFER',
                'offer_amount' => 400,
            ])
            ->assertCreated();

        // An offer is a decision waiting on the seller, not a remark, so it
        // gets its own notification rather than the generic message one.
        // Counted rather than asserted absent: the opening "Interested" above
        // is a real message and correctly sent one of its own, so the thing
        // being checked is that the OFFER did not send a second.
        Notification::assertSentToTimes($this->seller, OfferReceived::class, 1);
        Notification::assertSentToTimes($this->seller, MessageReceived::class, 1);

        $offer = Message::where('message_type', 'PRICE_OFFER')->sole();

        $this->actingAs($this->seller)
            ->postJson("/api/messages/{$offer->id}/respond", ['action' => 'accept'])
            ->assertOk();

        Notification::assertSentTo($this->buyer, OfferAnswered::class);
    }

    /**
     * The bell endpoint itself.
     *
     * The row is written directly rather than by sending a notification,
     * because what is under test here is reading and clearing, and going
     * through a real sale to arrange one would make this a test of the sale.
     */
    public function test_the_bell_lists_your_own_notifications_and_marks_them_read(): void
    {
        $this->seller->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => MessageReceived::class,
            'data' => ['kind' => 'message', 'title' => 'Message from someone', 'body' => 'Hello', 'path' => '/messages/1'],
        ]);

        $response = $this->actingAs($this->seller)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.title', 'Message from someone')
            ->assertJsonPath('data.0.read', false);

        $id = $response->json('data.0.id');

        $this->actingAs($this->seller)
            ->postJson('/api/notifications/read', ['id' => $id])
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->actingAs($this->seller)->getJson('/api/notifications')
            ->assertJsonPath('data.0.read', true);
    }

    /** Somebody else's bell is not yours to read or to clear. */
    public function test_the_bell_shows_nobody_elses_notifications(): void
    {
        $this->seller->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => MessageReceived::class,
            'data' => ['kind' => 'message', 'title' => 'Private', 'body' => '', 'path' => '/'],
        ]);

        $this->actingAs($this->buyer)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonCount(0, 'data');

        // And clearing everything as the buyer leaves the seller's unread.
        $this->actingAs($this->buyer)->postJson('/api/notifications/read')->assertOk();

        $this->assertSame(1, $this->seller->unreadNotifications()->count());
    }
}
