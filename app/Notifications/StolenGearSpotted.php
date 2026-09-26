<?php

namespace App\Notifications;

use App\Models\Listing;

/**
 * "Something with your stolen instrument's serial number is for sale."
 *
 * Sent to whoever filed the stolen report. Worded to steer them towards the
 * police and away from confronting the seller: serials do collide, the
 * seller may have bought it in good faith, and a moderator is already
 * looking at it with checkout on hold.
 */
class StolenGearSpotted extends MarketplaceNotification
{
    public function __construct(private readonly Listing $listing) {}

    public function kind(): string
    {
        return 'stolen';
    }

    public function title(object $notifiable): string
    {
        return 'A listing matches the serial you reported stolen';
    }

    public function body(object $notifiable): string
    {
        return '"'.$this->listing->title.'" has the same serial number. It cannot be bought while our team '
            .'reviews it. If it is yours, please give the police your crime reference and this listing '
            .'rather than contacting the seller yourself.';
    }

    public function path(object $notifiable): string
    {
        return '/listing/'.$this->listing->id;
    }
}
