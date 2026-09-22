<?php

namespace App\Notifications;

use App\Models\Message;

/**
 * Somebody has made an offer on one of your listings.
 *
 * Emailed, unlike an ordinary message, because an offer is a decision
 * waiting on the seller rather than a conversation, and one nobody answers
 * is a sale that did not happen.
 */
class OfferReceived extends MarketplaceNotification
{
    public function __construct(private readonly Message $offer) {}

    public function kind(): string
    {
        return 'offer';
    }

    public function title(object $notifiable): string
    {
        return 'Offer of £'.$this->offer->offer_amount.' on '.$this->offer->conversation->listing->title;
    }

    public function body(object $notifiable): string
    {
        return 'The listing is up at £'.$this->offer->conversation->listing->price
            .'. Accepting fixes the price for that buyer for '.config('services.stripe.offer_hours')
            .' hours; it does not take the listing off sale, so you can still sell it to somebody else in the meantime.';
    }

    public function path(object $notifiable): string
    {
        return '/messages/'.$this->offer->conversation_id;
    }
}
