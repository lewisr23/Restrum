<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to record that a message was steering someone off the platform.
 *
 * Escrow, refunds and Stripe's identity checks all stop mattering the moment
 * a buyer is talked into a bank transfer, and messaging was the one place
 * none of that reached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // The names of the signals that matched, or null for the
            // overwhelming majority of messages that matched nothing. Stored
            // as names rather than a boolean so the warning can say what it
            // spotted, and so a pattern that turns out to be noisy can be
            // found and retired later.
            $table->json('safety_flags')->nullable()->after('read_by_recipient');
        });

        // Reports can now be raised by the system rather than a person.
        // MySQL will not change a column that a foreign key is sitting on,
        // hence dropping and re-adding it around the change.
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['reporter_id']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('reporter_id')->nullable()->change();
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreign('reporter_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('safety_flags');
        });

        // Automatic reports have no reporter, so they cannot survive the
        // column becoming NOT NULL again. Removing them is the only honest
        // way back, and they are reproducible: the messages that caused
        // them still carry their flags.
        DB::table('reports')->whereNull('reporter_id')->delete();

        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['reporter_id']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('reporter_id')->nullable(false)->change();
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreign('reporter_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
