<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            // Both columns (not just created_at) so User::savedListings()'s
            // withTimestamps() pivot helper works without a special case -
            // these rows are never actually updated, but Eloquent's pivot
            // timestamp sugar expects both.
            $table->timestamps();

            $table->unique(['user_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_listings');
    }
};
