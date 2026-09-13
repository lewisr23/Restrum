<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Stripe refused, or could not be reached.
 *
 * A single exception type across the whole gateway so callers can decide what
 * a payment failure means for them without importing Stripe's own class
 * hierarchy into controllers and jobs.
 */
class PaymentGatewayException extends RuntimeException {}
