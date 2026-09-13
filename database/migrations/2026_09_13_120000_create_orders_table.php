<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();

            // Denormalised from the listing on purpose. Who sold the item is a
            // fact about the sale, fixed at the moment it happened, and must
            // not follow the listing if it is ever reassigned or edited.
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();

            // Same reasoning: the agreed price, not the listing's current one.
            // An accepted offer changes listings.price, so reading the price
            // back off the listing would misreport historical sales.
            $table->decimal('amount', 10, 2);

            // What the platform keeps. Stored per order rather than read from
            // config at payout time, so changing the fee never rewrites the
            // economics of sales that already happened.
            $table->decimal('platform_fee', 10, 2)->default(0);

            // ISO 4217. Single currency today, but a column is far cheaper now
            // than a backfill across historical orders later.
            $table->char('currency', 3)->default('GBP');

            $table->enum('status', array_column(OrderStatus::cases(), 'value'))
                ->default(OrderStatus::PENDING->value);

            // How long this checkout holds the instrument off the market.
            //
            // A PENDING order takes no money, so it cannot be allowed to
            // block a listing forever, but it has to block it for SOME time
            // or two buyers can both reach Stripe for one instrument and the
            // loser is refunded a purchase they thought they had made. A
            // reservation with an expiry is the middle ground, and putting
            // the expiry on the order rather than the listing means the
            // listing needs no RESERVED state and no unwinding when the
            // window lapses.
            $table->timestamp('reserved_until')->nullable();

            // Stripe identifiers. Nullable because an order exists before
            // Checkout is created, and unique because that is what makes
            // webhook handling idempotent: Stripe retries deliveries and can
            // send them out of order, so the database, not application logic,
            // is what guarantees one payment cannot be recorded twice.
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_transfer_id')->nullable()->unique();

            // Where to send a buyer who comes back to an order they started
            // but never paid for. Stored rather than re-requested, so
            // resuming a checkout costs no Stripe round trip and does not
            // depend on Stripe replaying an idempotent request to hand the
            // same URL back. Not unique: it is a pointer to the session
            // above, which already is.
            $table->text('stripe_checkout_url')->nullable();

            // Set once, when the corresponding transition happens. Kept as
            // discrete columns rather than derived from an event log because
            // every one of them answers a question support will actually ask:
            // when was this paid, when was it confirmed, when did the seller
            // get the money.
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            // The two busiest reads: a buyer's purchases and a seller's sales,
            // both usually filtered by status.
            $table->index(['buyer_id', 'status']);
            $table->index(['seller_id', 'status']);

            // Payouts sweep for orders that are due a transfer.
            $table->index(['status', 'confirmed_at']);

            // The availability check on every checkout: does this listing
            // already have an order against it that holds funds or holds a
            // live reservation.
            $table->index(['listing_id', 'status', 'reserved_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
