<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connect onboarding moved to Stripe's Accounts v2, and a seller's account
 * there is a RECIPIENT: it receives transfers out of the platform balance
 * and never takes a card payment of its own, because the platform takes
 * those and holds the money.
 *
 * So there is no such thing as "charges enabled" for one of these accounts.
 * The capability that decides whether a seller can be paid is
 * stripe_transfers, and a column called stripe_charges_enabled holding its
 * status would be the sort of name that sends the next person reading it
 * looking for a card payment that does not exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('stripe_charges_enabled', 'stripe_transfers_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('stripe_transfers_enabled', 'stripe_charges_enabled');
        });
    }
};
