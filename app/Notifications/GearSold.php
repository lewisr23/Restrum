<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * "You sold something." The one nobody should ever have to discover by
 * refreshing a page.
 */
class GearSold extends MarketplaceNotification
{
    public function __construct(private readonly Order $order) {}

    public function kind(): string
    {
        return 'sale';
    }

    public function title(object $notifiable): string
    {
        return 'You sold '.$this->order->listing->title;
    }

    public function body(object $notifiable): string
    {
        return 'Payment of £'.$this->order->amount.' is held by Restrum. Post it, add the tracking number, '
            .'and the money is released to you once the buyer confirms it arrived.';
    }

    public function path(object $notifiable): string
    {
        return '/orders/'.$this->order->id;
    }
}
