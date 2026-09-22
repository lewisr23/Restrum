<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * The buyer's receipt, and their reminder that the money has not gone to the
 * seller yet. That second half is the part worth saying: a buyer who thinks
 * they have simply paid a stranger behaves differently from one who knows
 * the site is still holding it.
 */
class PaymentHeld extends MarketplaceNotification
{
    public function __construct(private readonly Order $order) {}

    public function kind(): string
    {
        return 'payment';
    }

    public function title(object $notifiable): string
    {
        return 'Payment taken for '.$this->order->listing->title;
    }

    public function body(object $notifiable): string
    {
        return '£'.$this->order->amount.' is held by Restrum, not paid to the seller. Tell us when it arrives '
            .'as described and we release it. Tell us if it does not and we refund you.';
    }

    public function path(object $notifiable): string
    {
        return '/orders/'.$this->order->id;
    }
}
