<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An accepted offer becomes a price, not a sale.
 *
 * Accepting used to mark the listing SOLD and overwrite its price on the
 * spot. That was correct in the Gumtree-shaped version of this site, where
 * the two of them settled up privately and "sold" only ever meant "stop
 * showing this". Once escrow arrived it became the one way to take an
 * instrument off the market without anybody paying for it: no order, no
 * payment, no protection, and an asking price permanently rewritten to
 * whatever the seller last agreed to.
 *
 * So an accepted offer now grants the buyer a limited right to buy at the
 * agreed price, and the sale still happens through checkout like any other.
 * Two columns carry that:
 *
 * - messages.offer_expires_at, because a price agreed on Tuesday cannot
 *   still be claimable in March. It also decides, on its own, when an offer
 *   stops applying: nothing sweeps this, the checkout simply stops finding
 *   it.
 * - orders.offer_id, so an order priced below its listing says why. Without
 *   it the only record of the discount is a number that no longer matches
 *   anything, which is exactly the sort of thing a seller queries later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('offer_expires_at')->nullable()->after('offer_status');
        });

        Schema::table('orders', function (Blueprint $table) {
            // nullOnDelete rather than cascade: the sale is the record that
            // matters, and it must survive the conversation it came out of
            // being deleted. Losing the provenance is acceptable, losing the
            // order is not.
            $table->foreignId('offer_id')->nullable()->after('seller_id')
                ->constrained('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offer_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('offer_expires_at');
        });
    }
};
