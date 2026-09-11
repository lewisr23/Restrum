<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $viewerId = $request->user()->id;

        $conversations = Conversation::query()
            ->where('buyer_id', $viewerId)
            ->orWhere('seller_id', $viewerId)
            ->with(['listing', 'buyer', 'seller', 'latestMessage'])
            ->withCount(['messages as unread_count' => fn ($q) => $q
                ->where('read_by_recipient', false)
                ->where('sender_id', '!=', $viewerId)])
            ->orderByDesc('last_message_at')
            ->get();

        return ConversationResource::collection($conversations);
    }

    public function show(Request $request, Conversation $conversation)
    {
        $this->authorize('participate', $conversation);

        $conversation->load('listing', 'buyer', 'seller', 'latestMessage');
        $messages = $conversation->messages()->orderBy('created_at')->get();

        // Viewing the thread is what marks it read, so an unread badge only
        // clears once someone has actually opened and looked at the
        // conversation - not merely seen it listed in their inbox.
        $conversation->messages()
            ->where('sender_id', '!=', $request->user()->id)
            ->where('read_by_recipient', false)
            ->update(['read_by_recipient' => true]);

        return response()->json([
            'conversation' => new ConversationResource($conversation),
            'messages' => MessageResource::collection($messages),
        ]);
    }
}
