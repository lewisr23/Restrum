<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * An order, and the secret its buyer's browser needs to render and confirm
 * the payment form.
 *
 * The secret is not part of the order as far as callers are concerned, even
 * though it happens to be stored on the row: it is the answer to "what
 * happens next", and returning it separately keeps controllers from treating
 * a stored secret as something they may hand out on any other request.
 */
readonly class StartedCheckout
{
    public function __construct(
        public Order $order,
        public string $clientSecret,
        /** False when this resumed a checkout the buyer had already started. */
        public bool $isNew,
    ) {}
}
