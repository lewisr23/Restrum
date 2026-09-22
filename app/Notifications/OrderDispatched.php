<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * "It is on its way."
 *
 * Also the notification that makes the auto-release clock defensible. That
 * clock pays the seller after a fortnight whether or not the buyer says
 * anything, which is only fair if the buyer was actually told the parcel had
 * been sent and had the chance to say it never came.
 */
class OrderDispatched extends MarketplaceNotification
{
    public function __construct(private readonly Order $order) {}

    public function kind(): string
    {
        return 'dispatch';
    }

    public function title(object $notifiable): string
    {
        return $this->order->listing->title.' has been posted';
    }

    public function body(object $notifiable): string
    {
        $tracking = $this->order->tracking_number;

        return $tracking === null
            ? 'The seller has marked it as dispatched. Confirm it arrived as described and we release their payment.'
            : 'Tracking: '.trim($this->order->tracking_carrier.' '.$tracking)
                .'. Confirm it arrived as described and we release the seller\'s payment.';
    }

    public function path(object $notifiable): string
    {
        return '/orders/'.$this->order->id;
    }
}
