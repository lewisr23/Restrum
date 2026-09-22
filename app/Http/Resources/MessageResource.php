<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'content' => $this->content,
            'message_type' => $this->message_type,
            'offer_amount' => $this->offer_amount,
            'offer_status' => $this->offer_status,

            // When an accepted offer stops being claimable. Null on every
            // other kind of message, and on offers nobody has answered yet.
            // The client shows the deadline rather than computing one, since
            // the browser clock is not the one that decides.
            'offer_expires_at' => $this->offer_expires_at,
            'read_by_recipient' => $this->read_by_recipient,

            // Null for almost every message. When it is not, the client
            // shows a warning under the message rather than hiding it: see
            // OffPlatformScanner for why this warns instead of blocking.
            'safety_flags' => $this->safety_flags,
            'created_at' => $this->created_at,
        ];
    }
}
