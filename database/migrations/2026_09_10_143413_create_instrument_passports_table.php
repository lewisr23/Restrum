<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrument_passports', function (Blueprint $table) {
            $table->id();
            // One passport per listing - a listing either has a gear-history
            // timeline or it doesn't, so unique() rather than a plain index.
            $table->foreignId('listing_id')->unique()->constrained('listings')->cascadeOnDelete();
            $table->string('serial_number')->nullable();
            $table->unsignedSmallInteger('year_manufactured')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_passports');
    }
};
