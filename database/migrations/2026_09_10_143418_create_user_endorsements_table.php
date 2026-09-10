<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A user vouching for another user's trustworthiness, based on having
        // actually interacted with them - EndorsementService only allows this
        // between two users who share a Conversation. Once a user accumulates
        // enough endorsements, users.community_verified flips to true.
        Schema::create('user_endorsements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endorser_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('endorsed_id')->constrained('users')->cascadeOnDelete();
            // Both columns so User::endorsementsGiven()/Received()'s
            // withTimestamps() pivot helper works without a special case.
            $table->timestamps();

            $table->unique(['endorser_id', 'endorsed_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_endorsements');
    }
};
