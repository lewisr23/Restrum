<?php

namespace App\Notifications;

use App\Models\Order;

/** The buyer got their money back, whether they asked or the clock did. */
class OrderRefunded extends MarketplaceNotification
{
    public function __construct(private readonly Order $order) {}

    public function kind(): string
    {
        return 'refund';
    }

    public function title(object $notifiable): string
    {
        return 'Refunded: '.$this->order->listing->title;
    }

    public function body(object $notifiable): string
    {
        return '£'.$this->order->amount.' is going back to the card you paid with. Card refunds usually '
            .'show up within a few working days.';
    }

    public function path(object $notifiable): string
    {
        return '/orders/'.$this->order->id;
    }
}
