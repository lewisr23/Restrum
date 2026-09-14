<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment moved off Stripe's hosted Checkout and onto a Payment Element
 * mounted on our own checkout page, so the Stripe object behind an order
 * changed with it: a PaymentIntent rather than a Checkout Session.
 *
 * Two consequences for this table.
 *
 * There is no session any more, so stripe_checkout_session_id goes. Nothing
 * is lost with it: stripe_payment_intent_id already exists and is now
 * written when the payment is opened rather than when the webhook lands,
 * which makes it the identifier for the whole lifetime of the order instead
 * of only after the money arrived.
 *
 * And what the browser needs back is a client secret rather than a URL to
 * send the buyer to, so the column holding it is renamed. A column called
 * stripe_checkout_url holding a secret would be a lie the next person reads
 * as truth.
 *
 * Note what this does to idempotency, since the original table comment
 * claimed the unique constraint on stripe_payment_intent_id was what made
 * webhook handling safe to repeat. It no longer is, because the id is
 * present before any webhook arrives. The protection was always really the
 * status transition whitelist in the order lifecycle, which refuses a second
 * move to PAID; the constraint now does the narrower job of stopping two
 * orders claiming one payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['stripe_checkout_session_id']);
            $table->dropColumn('stripe_checkout_session_id');

            $table->renameColumn('stripe_checkout_url', 'stripe_payment_intent_client_secret');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('stripe_payment_intent_client_secret', 'stripe_checkout_url');

            $table->string('stripe_checkout_session_id')->nullable()->unique();
        });
    }
};
