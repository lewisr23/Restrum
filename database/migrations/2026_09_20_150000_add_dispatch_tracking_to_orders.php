<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that the seller actually sent the thing.
 *
 * Without this the escrow clock was a scam waiting to happen: auto-confirm
 * keyed off paid_at alone, so a seller could take the money, post nothing,
 * and be paid automatically a fortnight later unless the buyer noticed and
 * complained in time. Silence favoured the seller, which is precisely
 * backwards - the buyer is the one who has already parted with money.
 *
 * With a dispatch record the clock starts when the parcel does, and an
 * order that was never dispatched is refunded to the buyer instead of
 * released to the seller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('dispatched_at')->nullable()->after('paid_at');
            $table->string('tracking_carrier', 60)->nullable()->after('dispatched_at');
            $table->string('tracking_number', 60)->nullable()->after('tracking_carrier');

            // The sweep looks for paid orders that were never dispatched, so
            // it reads exactly these two columns on every run.
            $table->index(['status', 'dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'dispatched_at']);
            $table->dropColumn(['dispatched_at', 'tracking_carrier', 'tracking_number']);
        });
    }
};
