<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback that costs something to give.
 *
 * Endorsements already existed but are gated on having had a CONVERSATION,
 * which two colluding accounts can manufacture in a minute: message each
 * other, endorse each other, and arrive at a reputation nobody earned. A
 * reputation signal that can be faked for free is worse than none, because
 * it launders a stranger into looking trustworthy.
 *
 * A review here requires a completed order, which means somebody moved real
 * money through Stripe and paid the platform fee. That is not impossible to
 * game, but it stops being free, and cost is the only thing that has ever
 * made feedback mean anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();

            // Buyers and sellers are judged on different things - one on
            // describing and posting it, the other on paying and collecting -
            // so the side matters when reading an average.
            $table->enum('reviewer_role', ['BUYER', 'SELLER']);

            $table->timestamps();

            // One review per person per order. Without this a grudge is an
            // unlimited supply of one-star ratings.
            $table->unique(['order_id', 'reviewer_id']);

            // Profiles read every review written ABOUT someone, newest first.
            $table->index(['subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
