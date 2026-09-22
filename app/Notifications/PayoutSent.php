<?php

namespace App\Notifications;

use App\Models\Order;

/** The money has actually left the platform and is on its way to a bank. */
class PayoutSent extends MarketplaceNotification
{
    public function __construct(private readonly Order $order) {}

    public function kind(): string
    {
        return 'payout';
    }

    public function title(object $notifiable): string
    {
        return 'You have been paid for '.$this->order->listing->title;
    }

    public function body(object $notifiable): string
    {
        return '£'.$this->order->sellerProceeds().' is on its way to your bank through Stripe. '
            .'How long it takes to land is your bank\'s business rather than ours, but it is out of our hands now.';
    }

    public function path(object $notifiable): string
    {
        return '/orders/'.$this->order->id;
    }
}
