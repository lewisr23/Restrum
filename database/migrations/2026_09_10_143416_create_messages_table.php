<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->enum('message_type', ['TEXT', 'PRICE_OFFER'])->default('TEXT');
            // Only set when message_type = PRICE_OFFER; null for plain TEXT rows.
            $table->decimal('offer_amount', 10, 2)->nullable();
            $table->enum('offer_status', ['PENDING', 'ACCEPTED', 'DECLINED'])->nullable();
            $table->boolean('read_by_recipient')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
