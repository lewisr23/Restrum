<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telling people what happened to their sale.
 *
 * Until now the site told nobody anything. A seller found out they had sold
 * something by opening the site and looking; a buyer found out their parcel
 * had been posted the same way. That is survivable on a page people visit
 * daily and useless on a marketplace they visit when they remember it, and
 * it sits particularly badly next to a clock: the escrow releases money
 * automatically after a fortnight, and a buyer who never learned their item
 * shipped had no reason to come back and say it had not arrived.
 *
 * Laravel's own shape, kept exactly: a morph to the notifiable, the rendered
 * contents as JSON, and read_at doubling as the flag and the timestamp.
 * Anything custom here would mean giving up the framework's notification
 * plumbing for no gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            // A UUID rather than an auto-increment, because these are handed
            // to the client to mark individually as read and a sequential id
            // would let one person count how much the site is doing.
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Every read is "this person's, newest first" or "this person's,
            // still unread", and morphs() alone only indexes the pair.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
