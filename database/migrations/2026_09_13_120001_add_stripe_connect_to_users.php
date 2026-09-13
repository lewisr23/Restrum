<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sellers are paid through their own Stripe connected account, so the link
 * between a Restrum user and that account lives here.
 *
 * Only sellers ever get one. Buyers pay the platform with a card and never
 * need an account of their own, which is why every column is nullable rather
 * than this being a separate required table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Stripe's acct_... identifier. Unique because two users sharing
            // a connected account would mean one seller's sales paying out to
            // another's bank, and the database is the right place to make
            // that impossible rather than a code path that must be remembered.
            $table->string('stripe_account_id')->nullable()->unique()->after('community_verified');

            // Mirrors of Stripe's own flags, kept locally because the
            // alternative is an API call on every listing page to answer
            // "can this person actually be paid". Stripe pushes changes via
            // the account.updated webhook, so this is a cache with an
            // invalidation path rather than a second source of truth.
            //
            // Both matter and they are not the same thing: charges_enabled
            // says the account may be part of a payment at all, while
            // payouts_enabled says money can actually reach their bank. A
            // seller missing either one must not be able to list for sale,
            // because the money would arrive nowhere.
            $table->boolean('stripe_charges_enabled')->default(false)->after('stripe_account_id');
            $table->boolean('stripe_payouts_enabled')->default(false)->after('stripe_charges_enabled');

            // When Stripe last told us the above. Useful in support: "your
            // account says unverified" is a very different conversation
            // depending on whether that reflects five minutes ago or a
            // webhook we never received.
            $table->timestamp('stripe_synced_at')->nullable()->after('stripe_payouts_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The unique index has to go before the column it is built on.
            $table->dropUnique(['stripe_account_id']);
            $table->dropColumn([
                'stripe_account_id',
                'stripe_charges_enabled',
                'stripe_payouts_enabled',
                'stripe_synced_at',
            ]);
        });
    }
};
