<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

// Same rule as ConversationPolicy::participate - only the two people
// actually in the thread can subscribe to its live channel. Looking the
// conversation up here rather than trusting the client is the whole point:
// this callback IS the authorization check for the WebSocket connection.
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation
        && ($user->id === $conversation->buyer_id || $user->id === $conversation->seller_id);
});
