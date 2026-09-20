<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Only the two people whose money it is can see an order.
     *
     * Buyer and seller both, because an order is as much a record of a sale
     * as it is a record of a purchase, and a seller who cannot see what they
     * have sold has no way to know to post it.
     */
    public function view(User $user, Order $order): bool
    {
        return $user->id === $order->buyer_id || $user->id === $order->seller_id;
    }

    /**
     * Only the seller can say they posted it.
     *
     * Obvious, and worth enforcing rather than assuming: this claim is what
     * starts the clock that eventually pays the seller, so a buyer being
     * able to make it would hand the scam back.
     */
    public function dispatch(User $user, Order $order): bool
    {
        return $user->id === $order->seller_id;
    }
}
