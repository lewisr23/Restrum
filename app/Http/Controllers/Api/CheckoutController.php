<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Listing;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\PaymentGatewayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Starting a purchase.
 *
 * Replaces the old POST /listings/{listing}/buy, which marked a listing SOLD
 * and took no money. That endpoint was the honest version of a demo and the
 * dishonest version of a marketplace.
 */
class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout) {}

    public function store(Request $request, Listing $listing): JsonResponse
    {
        try {
            $started = $this->checkout->start($request->user(), $listing);
        } catch (PaymentGatewayException $e) {
            // The reservation has already been released by the service, so
            // the listing is not stranded by this. Nothing about Stripe's
            // internals goes back to the buyer.
            Log::error('Could not open a Stripe checkout.', [
                'listing_id' => $listing->id,
                'buyer_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Payments are temporarily unavailable. Nothing has been charged, please try again shortly.',
            ], 503);
        }

        return response()->json([
            'order' => new OrderResource($started->order->load('listing', 'seller')),

            // The only place this URL is ever handed out. It is a live way to
            // pay for an instrument, so it goes to the buyer who reserved it
            // in the response to the request that reserved it, and nowhere
            // else - notably not from the order endpoints, which a seller can
            // read too.
            'checkout_url' => $started->url,
            'resumed' => ! $started->isNew,
        ], $started->isNew ? 201 : 200);
    }
}
