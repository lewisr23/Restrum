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
            'read_by_recipient' => $this->read_by_recipient,
            'created_at' => $this->created_at,
        ];
    }
}
