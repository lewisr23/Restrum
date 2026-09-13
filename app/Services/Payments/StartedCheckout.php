<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * An order and the Stripe page the buyer now has to be sent to.
 *
 * The URL is not part of the order as far as callers are concerned, even
 * though it happens to be stored on the row: it is the answer to "what
 * happens next", and returning it separately keeps controllers from treating
 * a stored URL as something they may hand out on any other request.
 */
readonly class StartedCheckout
{
    public function __construct(
        public Order $order,
        public string $url,
        /** False when this resumed a checkout the buyer had already started. */
        public bool $isNew,
    ) {}
}
