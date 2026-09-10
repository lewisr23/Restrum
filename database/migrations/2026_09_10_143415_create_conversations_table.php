<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            // Denormalised copy of the listing's seller at creation time, same
            // reason the Java version kept it: the seller side of a thread is
            // implied by the listing, but copying it here avoids a join on
            // every inbox load and survives the listing being reassigned later.
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->useCurrent();
            $table->timestamps();

            // One conversation per (listing, buyer) pair - the seller side is
            // implied by the listing, so re-messaging about the same item
            // reopens the same thread rather than creating a new one.
            $table->unique(['listing_id', 'buyer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
