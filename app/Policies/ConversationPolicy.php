<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /** Only the two people actually in the thread can see or post in it. */
    public function participate(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->buyer_id || $user->id === $conversation->seller_id;
    }
}
