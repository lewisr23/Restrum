<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldBroadcastNow (synchronous), not ShouldBroadcast (queued) - a queued
 * broadcast needs a running queue worker to ever actually go out, which is
 * one more background process to keep alive for what is, functionally, a
 * single fast HTTP call away from a WebSocket push. Worth revisiting if this
 * ever needs to survive Reverb being briefly unreachable without blocking
 * the request, but that's a real-traffic problem, not this app's problem yet.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->message->conversation_id)];
    }

    /** Custom name so the frontend listens for "message.sent", not the fully-qualified PHP class name. */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return (new MessageResource($this->message))->resolve();
    }
}
