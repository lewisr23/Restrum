<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postage, which the marketplace had no concept of at all.
 *
 * Until now a buyer saw a price, paid exactly that into escrow, and then had
 * to agree postage with the seller privately, after the money had already
 * moved. The Terms said so out loud. That is fine for a classifieds board and
 * wrong for somewhere that holds the funds: the one number the buyer needs
 * before committing is what it will cost to get the thing to them.
 *
 * On the listing: either the seller posts it for a stated price, or it is
 * collection only. Nullable postage_price with collection_only false means
 * free postage rather than "unknown", because a listing that cannot say what
 * postage costs is the problem this solves.
 *
 * On the order: postage records how much of the total was carriage, so the
 * platform fee can be charged on the item alone. Taking a percentage of a
 * seller's stamp money is the sort of thing sellers notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->decimal('postage_price', 10, 2)->default(0)->after('price');
            $table->boolean('collection_only')->default(false)->after('postage_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Defaults to zero so every order written before this migration
            // stays arithmetically true: amount was the item price, and the
            // postage part of it was nothing.
            $table->decimal('postage', 10, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['postage_price', 'collection_only']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('postage');
        });
    }
};
