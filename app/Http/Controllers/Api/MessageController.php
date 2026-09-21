<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
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
        // ListingController::buy takes the listing lock on its own, so as
        // long as every path that sells a listing grabs that row first, the
        // two cannot deadlock against each other.
        $message = DB::transaction(function () use ($message, $conversation, $data) {
            $listing = Listing::whereKey($conversation->listing_id)->lockForUpdate()->firstOrFail();
            $offer = Message::whereKey($message->getKey())->lockForUpdate()->firstOrFail();

            if ($offer->message_type !== 'PRICE_OFFER' || $offer->offer_status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'action' => 'This offer has already been resolved.',
                ]);
            }

            // Previously unchecked: nothing stopped a seller accepting an
            // offer on a listing that had already sold, through buy() or
            // through an offer accepted moments earlier in another
            // conversation. That silently re-sold it and overwrote the price.
            // Declining stays allowed, since tidying up a dead offer on a
            // sold listing is reasonable and changes nothing about the sale.
            if ($data['action'] === 'accept' && $listing->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'action' => 'This listing is no longer available.',
                ]);
            }

            $offer->offer_status = $data['action'] === 'accept' ? 'ACCEPTED' : 'DECLINED';
            $offer->save();

            if ($data['action'] === 'accept') {
                // Direct property assignment + save(), not update() - 'price' and
                // 'status' both need to change together here and this is the one
                // place a listing legitimately moves to SOLD; going through the
                // normal fillable update() path would both violate status's
                // mass-assignment guard (see ListingController::store) and make
                // it too easy to accidentally mark something sold from a
                // different code path later.
                $listing->price = $offer->offer_amount;
                $listing->status = 'SOLD';
                $listing->save();
            }

            return $offer;
        });

        // Re-broadcasts the SAME message id with its new offer_status - the
        // frontend listener needs to treat an already-known id as "update in
        // place", not "append a new bubble", for this to render correctly.
        MessageSent::dispatch($message);

        return new MessageResource($message);
    }
}
