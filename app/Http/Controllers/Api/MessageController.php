<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
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

        // A sold listing deliberately does NOT block a new conversation here.
        // This marketplace processes no payments: buying marks the item sold
        // and everything that actually completes the sale - paying, agreeing
        // collection or postage - happens in the conversation afterwards. An
        // earlier version refused new conversations on sold listings, which
        // blocked the buyer from the exact next step the checkout page tells
        // them to take, every time. Nor can the buyer be special-cased: buy()
        // deliberately records no purchase, so there is nothing to check them
        // against - and messaging a seller about something already sold is
        // ordinary marketplace behaviour regardless.
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

        if ($message->message_type !== 'PRICE_OFFER' || $message->offer_status !== 'PENDING') {
            throw ValidationException::withMessages([
                'action' => 'This offer has already been resolved.',
            ]);
        }

        $message->offer_status = $data['action'] === 'accept' ? 'ACCEPTED' : 'DECLINED';
        $message->save();

        if ($data['action'] === 'accept') {
            // Direct property assignment + save(), not update() - 'price' and
            // 'status' both need to change together here and this is the one
            // place a listing legitimately moves to SOLD; going through the
            // normal fillable update() path would both violate status's
            // mass-assignment guard (see ListingController::store) and make
            // it too easy to accidentally mark something sold from a
            // different code path later.
            $conversation->listing->price = $message->offer_amount;
            $conversation->listing->status = 'SOLD';
            $conversation->listing->save();
        }

        // Re-broadcasts the SAME message id with its new offer_status - the
        // frontend listener needs to treat an already-known id as "update in
        // place", not "append a new bubble", for this to render correctly.
        MessageSent::dispatch($message);

        return new MessageResource($message);
    }
}
