<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeEvent;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where Stripe tells the marketplace that money moved.
 *
 * The only unauthenticated endpoint that changes anything, which is why it
 * does exactly two things before handing off: check the signature, and answer
 * quickly. Neither is optional. An unsigned webhook is a stranger marking
 * orders paid, and a slow one is Stripe retrying an event the app is already
 * processing.
 */
class StripeWebhookController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $event = $this->gateway->parseWebhook(
                // The RAW body. Anything that re-encodes the JSON - reading it
                // through $request->all() and putting it back together -
                // changes the bytes the signature was computed over and every
                // legitimate webhook starts failing.
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
            );
        } catch (PaymentGatewayException $e) {
            Log::warning('Rejected a Stripe webhook.', ['error' => $e->getMessage()]);

            // 400 rather than 401: Stripe treats any non-2xx as a failure and
            // retries, and there is no credential for the caller to correct.
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        // On the queue, not here. Handling it inline would mean this endpoint
        // takes as long as the slowest database write it triggers, and Stripe
        // gives up waiting long before it gives up retrying - which is how one
        // payment becomes several deliveries of the same event.
        ProcessStripeEvent::dispatch($event);

        return response()->json(['received' => true]);
    }
}
