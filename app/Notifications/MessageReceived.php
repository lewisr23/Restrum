<?php

namespace App\Notifications;

use App\Models\Message;

/**
 * Somebody wrote to you.
 *
 * The one notification that does NOT email. A conversation about whether a
 * pedal has its original box can run to a dozen messages in ten minutes, and
 * a dozen emails in ten minutes teaches a person to filter everything from
 * this site, including the one that says their money moved. The bell is
 * enough, and the chat is already live over the websocket for anyone who has
 * the page open.
 */
class MessageReceived extends MarketplaceNotification
{
    public function __construct(private readonly Message $message) {}

    public function kind(): string
    {
        return 'message';
    }

    public function emailsToo(): bool
    {
        return false;
    }

    public function title(object $notifiable): string
    {
        return 'Message from '.$this->message->sender->username;
    }

    public function body(object $notifiable): string
    {
        return str($this->message->content)->limit(120)->toString();
    }

    public function path(object $notifiable): string
    {
        return '/messages/'.$this->message->conversation_id;
    }
}
