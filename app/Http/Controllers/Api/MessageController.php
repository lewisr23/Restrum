<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MessageReceived;
use App\Notifications\OfferAnswered;
use App\Notifications\OfferReceived;
use App\Services\Safety\MessageSafetyReviewer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function __construct(private MessageSafetyReviewer $safety) {}

    /**
     * Starts a conversation with a listing's seller if one doesn't already
     * exist for this (listing, buyer) pair, or continues it if it does -
     * one conversation per pair, matching the unique constraint on
     * conversations. Also sends the first/next message in the same request,
     * since a buyer never "creates an empty conversation" in the real UI,
     * they always type something first.
     */
    public function startOrContinue(Request $request, Listing $listing)
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
        ]);

        if ($request->user()->id === $listing->seller_id) {
            throw ValidationException::withMessages([
                'listing' => 'You cannot message yourself about your own listing.',
            ]);
        }

        $conversation = Conversation::firstOrNew([
            'listing_id' => $listing->id,
            'buyer_id' => $request->user()->id,
        ]);

        // A sold listing deliberately does NOT block a new conversation.
        // Selling is only the start of the exchange: the buyer still has to
        // ask where their parcel is, agree a collection time, or sort out a
        // problem with what arrived, and every one of those conversations
        // begins after the listing stops being available. An earlier
        // version refused them, which shut the buyer out at exactly the
        // point they most needed to reach the seller. Messaging a seller
        // about something already sold is ordinary marketplace behaviour
        // regardless of who is asking.
        //
        // (This comment used to say the site processed no payments and that
        // buyer and seller settled up privately in the conversation. That
        // stopped being true when Stripe escrow landed, and the rule it was
        // justifying is now justified by the paragraph above instead.)
        if (! $conversation->exists) {
            $conversation->seller_id = $listing->seller_id;
        }
        $conversation->last_message_at = now();
        $conversation->save();

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'content' => $data['content'],
            'message_type' => 'TEXT',
        ]);
        // read_by_recipient is a DB-level default rather than part of this
        // create() payload - refresh() pulls the stored value back instead of
        // leaving it null in the response (same class of issue as
        // ListingController::store's status/condition handling). created_at
        // no longer needs this: the model writes it on the app's clock now.
        $message->refresh();

        // Before the broadcast, so a message that arrives live carries its
        // warning with it rather than growing one on the next page load.
        $this->safety->review($message);

        MessageSent::dispatch($message);
        $this->tell($conversation, $message);

        return response()->json([
            'conversation' => new ConversationResource($conversation->load('listing', 'buyer', 'seller')),
            'message' => new MessageResource($message),
        ], 201);
    }

    /** A reply (or a price offer) within an existing conversation. */
    public function store(Request $request, Conversation $conversation)
    {
        $this->authorize('participate', $conversation);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
            'message_type' => ['nullable', 'string', 'in:TEXT,PRICE_OFFER'],
            'offer_amount' => ['required_if:message_type,PRICE_OFFER', 'nullable', 'numeric', 'min:0.01'],
        ]);

        $isOffer = ($data['message_type'] ?? 'TEXT') === 'PRICE_OFFER';

        if ($isOffer && $request->user()->id !== $conversation->buyer_id) {
            throw ValidationException::withMessages([
                'message_type' => 'Only the buyer can make a price offer.',
            ]);
        }

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'content' => $data['content'],
            'message_type' => $isOffer ? 'PRICE_OFFER' : 'TEXT',
            'offer_amount' => $isOffer ? $data['offer_amount'] : null,
            'offer_status' => $isOffer ? 'PENDING' : null,
        ]);
        $message->refresh(); // see startOrContinue() above for why
        $this->safety->review($message);
        MessageSent::dispatch($message);
        $this->tell($conversation, $message);

        // Direct assignment, not update(['last_message_at' => ...]) -
        // last_message_at is deliberately not in Conversation's #[Fillable]
        // list, so the mass-assignment form silently discarded it and left
        // the inbox (ordered by this column) frozen at the first message's
        // time. Same trap as ListingController::store's status handling.
        $conversation->last_message_at = now();
        $conversation->save();

        return new MessageResource($message);
    }

    /** Seller accepts or declines a buyer's pending price offer. */
    public function respond(Request $request, Message $message)
    {
        $conversation = $message->conversation;
        $this->authorize('participate', $conversation);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:accept,decline'],
        ]);

        if ($request->user()->id !== $conversation->seller_id) {
            throw ValidationException::withMessages([
                'action' => 'Only the seller can respond to an offer.',
            ]);
        }

        // Both the offer's PENDING check and the listing's availability are
        // read-then-write, so they run in one transaction with the rows held.
        // The listing lock is taken BEFORE the message lock deliberately:
        // CheckoutService::reserve takes the listing lock on its own, so as
        // long as every path that sells a listing grabs that row first, the
        // two cannot deadlock against each other. This path no longer sells
        // anything, but it still decides on the listing's status, and the
        // lock ordering is worth keeping consistent for whatever is added
        // next rather than rediscovering it.
        $message = DB::transaction(function () use ($message, $conversation, $data) {
            $listing = Listing::whereKey($conversation->listing_id)->lockForUpdate()->firstOrFail();
            $offer = Message::whereKey($message->getKey())->lockForUpdate()->firstOrFail();

            if ($offer->message_type !== 'PRICE_OFFER' || $offer->offer_status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'action' => 'This offer has already been resolved.',
                ]);
            }

            // Accepting an offer on something already sold is meaningless:
            // there is nothing left to sell at the agreed price. Declining
            // stays allowed, since tidying up a dead offer on a sold listing
            // is reasonable and changes nothing about the sale.
            if ($data['action'] === 'accept' && $listing->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'action' => 'This listing is no longer available.',
                ]);
            }

            $offer->offer_status = $data['action'] === 'accept' ? 'ACCEPTED' : 'DECLINED';

            // What accepting now does, and does not do. It does NOT mark the
            // listing sold or rewrite its price, which is what it used to do
            // and is the reason this method needed rebuilding: that path took
            // an instrument off the market with no order behind it, no money
            // moved, and no buyer protection, while permanently overwriting
            // the asking price with the last figure anyone happened to agree
            // to. What it does instead is give this buyer a window in which
            // checkout will charge the agreed price. The listing stays on
            // sale for the whole of that window, so a buyer who haggles and
            // then disappears costs the seller nothing, and whoever pays
            // first gets it - which is the same rule the reservation system
            // already applies to everyone else.
            if ($data['action'] === 'accept') {
                $offer->offer_expires_at = now()->addHours(
                    (int) config('services.stripe.offer_hours')
                );
            }

            $offer->save();

            return $offer;
        });

        // Re-broadcasts the SAME message id with its new offer_status - the
        // frontend listener needs to treat an already-known id as "update in
        // place", not "append a new bubble", for this to render correctly.
        MessageSent::dispatch($message);

        // The buyer is the one waiting on this answer, and an accepted offer
        // is a price with a deadline on it. Somebody who only finds out by
        // reopening the site has been given nothing.
        $conversation->buyer?->notify(new OfferAnswered($message->load('conversation.listing')));

        return new MessageResource($message);
    }

    /**
     * Put the new message in the other person's bell.
     *
     * An offer gets its own notification rather than a generic one, because
     * it is not a remark, it is a decision waiting on the seller, and it is
     * the only kind of message worth an email as well. See
     * MessageReceived::emailsToo() for why ordinary chat is not.
     */
    private function tell(Conversation $conversation, Message $message): void
    {
        $recipientId = $message->sender_id === $conversation->buyer_id
            ? $conversation->seller_id
            : $conversation->buyer_id;

        $recipient = User::find($recipientId);

        if ($recipient === null) {
            return;
        }

        $recipient->notify($message->message_type === 'PRICE_OFFER'
            ? new OfferReceived($message->load('conversation.listing'))
            : new MessageReceived($message->load('sender')));
    }
}
