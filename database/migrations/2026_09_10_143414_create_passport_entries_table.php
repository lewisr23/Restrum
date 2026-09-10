<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passport_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passport_id')->constrained('instrument_passports')->cascadeOnDelete();
            $table->enum('entry_type', [
                'ORIGINAL_PURCHASE', 'OWNERSHIP_CHANGE', 'SERVICE', 'REPAIR', 'MODIFICATION', 'OTHER',
            ]);
            $table->text('description');
            $table->date('event_date')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passport_entries');
    }
};
