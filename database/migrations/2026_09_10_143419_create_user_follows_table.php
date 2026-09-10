<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One user following another to see their new listings - lower-stakes
        // than UserEndorsement (no "you must have messaged them" gate, doesn't
        // affect community_verified). Purely a subscribe/follow relationship.
        Schema::create('user_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('followed_id')->constrained('users')->cascadeOnDelete();
            // Both columns so User::following()/followers()'s withTimestamps()
            // pivot helper works without a special case.
            $table->timestamps();

            $table->unique(['follower_id', 'followed_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_follows');
    }
};
