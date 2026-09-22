<?php

namespace App\Notifications;

use App\Models\Message;

/**
 * The seller said yes or no.
 *
 * Yes is the one that matters and it is the reason this is emailed: an
 * accepted offer is a price with a deadline on it, and a buyer who does not
 * find out until the deadline has passed has been given nothing at all.
 */
class OfferAnswered extends MarketplaceNotification
{
    public function __construct(private readonly Message $offer) {}

    public function kind(): string
    {
        return 'offer';
    }

    private function accepted(): bool
    {
        return $this->offer->offer_status === 'ACCEPTED';
    }

    public function title(object $notifiable): string
    {
        $title = $this->offer->conversation->listing->title;

        return $this->accepted()
            ? 'Your offer on '.$title.' was accepted'
            : 'Your offer on '.$title.' was declined';
    }

    public function body(object $notifiable): string
    {
        if (! $this->accepted()) {
            return 'It is still for sale at £'.$this->offer->conversation->listing->price
                .' if you want it, and you can make another offer.';
        }

        return 'Pay £'.$this->offer->offer_amount.' by '
            .$this->offer->offer_expires_at?->format('D j M, H:i')
            .' and it is yours at that price. It stays on sale to everyone else until you do, '
            .'so whoever pays first gets it.';
    }

    public function path(object $notifiable): string
    {
        return '/messages/'.$this->offer->conversation_id;
    }
}
