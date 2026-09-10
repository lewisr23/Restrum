<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->decimal('price', 10, 2);
            $table->string('location');
            // Category-specific structured attributes (pickup type, tuning,
            // drivetrain for pedals, etc.) land in a later migration once the
            // faceted filtering system is built - deliberately not here yet,
            // so this stays a boring, working core first.
            $table->enum('category', [
                'GUITAR', 'DRUMS', 'MICROPHONE', 'SYNTHS', 'AUDIO_EQUIPMENT',
            ]);
            $table->enum('condition', ['MINT', 'EXCELLENT', 'GOOD', 'FAIR'])->default('GOOD');
            $table->enum('status', ['ACTIVE', 'SOLD'])->default('ACTIVE');
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
