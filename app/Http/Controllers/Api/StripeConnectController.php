<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Seller onboarding: getting a private seller to the point where money can
 * reach their bank.
 *
 * Restrum never sees a bank account number. The seller fills Stripe's own
 * form, embedded in our page as a cross-origin iframe, and what comes back
 * here is two booleans saying whether Stripe is willing to pay them. That
 * division is the whole reason for using Connect rather than collecting
 * payment details directly.
 */
class StripeConnectController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Where the seller stands with Stripe.
     *
     * Reads the local mirror, and refreshes it from Stripe when asked. The
     * refresh matters at exactly one moment: the seller has just finished
     * onboarding and the account.updated webhook has not landed yet, so
     * the honest local answer is out of date by seconds.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->stripe_account_id === null) {
            return response()->json([
                'onboarded' => false,
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'can_sell' => false,
                'synced_at' => null,
            ]);
        }

        if ($request->boolean('refresh')) {
            $this->pull($user);
        }

        return response()->json($this->state($user));
    }

    /**
     * Start or resume Stripe onboarding.
     *
     * Safe to call repeatedly, and it will be: Stripe.js calls it again
     * whenever a session expires. A seller who abandoned the form halfway gets
     * a fresh session on the same account rather than a second account, which
     * matters because Stripe would happily create as many as it is asked to
     * and only one of them can be paid into.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            if ($user->stripe_account_id === null) {
                $accountId = $this->gateway->createConnectedAccount($user);

                // Saved before anything else can fail. An account created at
                // Stripe and not recorded here is orphaned: the seller is
                // sent through onboarding again, into a different account,
                // and the verified one is never used.
                $user->stripe_account_id = $accountId;
                $user->save();
            }

            // Decided here from what Restrum knows, never from the request,
            // since the setup session is the one without Stripe's sign-in
            // step and a client must not be able to ask for it.
            $settingUp = ! $user->canReceivePayments();

            $clientSecret = $this->gateway->createOnboardingSession($user->stripe_account_id, $settingUp);
        } catch (PaymentGatewayException $e) {
            Log::error('Could not start Stripe onboarding.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Stripe is not available right now. Please try again shortly.',
            ], 503);
        }

        return response()->json([
            'client_secret' => $clientSecret,
            // So the page mounts the components this session actually
            // contains, rather than guessing from state that may have moved.
            'mode' => $settingUp ? 'setup' : 'manage',
            // Served here for the same reason CheckoutController serves it:
            // switching to live keys is then a server config change, not a
            // frontend rebuild.
            'publishable_key' => (string) config('services.stripe.key'),
        ]);
    }

    /**
     * Ask Stripe for this account's current state and update the mirror.
     *
     * A failure here is not worth failing the request over: the mirror is
     * allowed to be stale, the account.updated webhook will correct it, and a
     * seller seeing yesterday's answer beats a seller seeing an error page.
     */
    private function pull(User $user): void
    {
        try {
            $state = $this->gateway->fetchAccountState($user->stripe_account_id);
        } catch (PaymentGatewayException $e) {
            Log::warning('Could not refresh a connected account.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $user->stripe_transfers_enabled = $state->transfersEnabled;
        $user->stripe_payouts_enabled = $state->payoutsEnabled;
        $user->stripe_synced_at = now();
        $user->save();
    }

    /** @return array<string, mixed> */
    private function state(User $user): array
    {
        return [
            'onboarded' => true,
            'transfers_enabled' => $user->stripe_transfers_enabled,
            'payouts_enabled' => $user->stripe_payouts_enabled,
            'can_sell' => $user->canReceivePayments(),
            'synced_at' => $user->stripe_synced_at,
        ];
    }
}
