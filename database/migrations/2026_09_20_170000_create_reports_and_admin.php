<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A way to say "this is a scam", and a lever to do something about it.
 *
 * There was previously neither. Someone who spotted a stolen instrument, a
 * fake listing or a seller pushing people to bank transfer had no button to
 * press, and the operator had no action short of editing the database by
 * hand. Reporting without tooling is a suggestion box; tooling without
 * reporting means nobody ever knows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();

            // Nullable and both present: a report is about a listing, or a
            // user, and which one decides what can be done about it.
            $table->foreignId('listing_id')->nullable()->constrained('listings')->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->enum('reason', [
                'SCAM',
                'STOLEN',
                'COUNTERFEIT',
                'OFF_PLATFORM_PAYMENT',
                'PROHIBITED',
                'ABUSE',
                'OTHER',
            ]);
            $table->text('detail')->nullable();

            $table->enum('status', ['OPEN', 'ACTIONED', 'DISMISSED'])->default('OPEN');
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // The queue is "everything still open, oldest first".
            $table->index(['status', 'created_at']);

            // One open report per person per thing. Without it a pile-on
            // looks like evidence, and one angry user can bury a seller.
            $table->unique(['reporter_id', 'listing_id', 'subject_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('community_verified');

            // Suspension rather than deletion. Deleting a user cascades
            // through their listings and orders, which would destroy the
            // record of a sale a real buyer was part of.
            $table->timestamp('suspended_at')->nullable()->after('is_admin');
            $table->string('suspension_reason')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'suspended_at', 'suspension_reason']);
        });
    }
};
