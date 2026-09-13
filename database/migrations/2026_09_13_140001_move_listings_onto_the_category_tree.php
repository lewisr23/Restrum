<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves listings off the five value `category` enum and onto the tree.
 *
 * The enum was five words covering an entire music shop, which made the
 * filtering as good as it could be and no better. It goes rather than staying
 * alongside category_id, because two columns describing the same thing is two
 * answers to "what is this", and the one nothing writes to is the one that
 * quietly goes stale.
 */
return new class extends Migration
{
    /**
     * Where each old value lands.
     *
     * Best guesses, and deliberately the most ordinary leaf under the right
     * branch rather than anything clever. AUDIO_EQUIPMENT is the awkward one:
     * it was a catch-all holding pedals, interfaces and PA gear alike, so
     * nothing it maps to will be right for all of them. Sellers can re-file,
     * and a wrong leaf under the right department is a much smaller problem
     * than the enum was.
     */
    private const MAPPING = [
        'GUITAR' => 'guitars/electric-guitars/solid-body-electric-guitars',
        'DRUMS' => 'drums-percussion/acoustic-drum-kits/rock-fusion-drum-kits',
        'MICROPHONE' => 'studio-recording/microphones/dynamic-microphones',
        'SYNTHS' => 'keys-synths/synthesisers/analogue-synthesisers',
        'AUDIO_EQUIPMENT' => 'studio-recording/audio-interfaces/usb-audio-interfaces',
    ];

    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Nullable for the length of this migration only, so existing
            // rows can be backfilled before the constraint goes on. The
            // application treats it as required; ListingController will not
            // accept a listing without one.
            $table->foreignId('category_id')->nullable()->after('location')
                ->constrained('categories')
                // A category with listings in it must not be deletable. The
                // listings would be unreachable and unfilterable, which is
                // worse than being told to move them first.
                ->restrictOnDelete();

            // Denormalised from the brand list rather than a foreign key.
            // Brands are a closed list in PHP (App\Catalog\Brands) with no
            // attributes of their own, so a table would buy a join and
            // nothing else.
            $table->string('brand', 80)->nullable()->after('category_id');
        });

        foreach (self::MAPPING as $old => $path) {
            $categoryId = DB::table('categories')->where('path', $path)->value('id');

            if ($categoryId === null) {
                // The tree is created by the migration immediately before this
                // one, so this cannot happen unless the taxonomy was edited
                // and a path renamed. Failing loudly beats silently leaving
                // listings with no category.
                throw new RuntimeException("Category path '{$path}' is missing; cannot migrate '{$old}' listings.");
            }

            DB::table('listings')->where('category', $old)->update(['category_id' => $categoryId]);
        }

        Schema::table('listings', function (Blueprint $table) {
            // The old composite index is built on the column about to be
            // dropped, so it has to go first or MySQL refuses.
            $table->dropIndex(['status', 'category']);
            $table->dropColumn('category');

            // Same shape as the index it replaces: browse pages filter on
            // availability and category together, in that order.
            $table->index(['status', 'category_id']);
            $table->index(['status', 'brand']);
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->enum('category', array_keys(self::MAPPING))->default('GUITAR')->after('location');
        });

        foreach (self::MAPPING as $old => $path) {
            $categoryId = DB::table('categories')->where('path', $path)->value('id');

            if ($categoryId !== null) {
                DB::table('listings')->where('category_id', $categoryId)->update(['category' => $old]);
            }
        }

        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['status', 'brand']);
            $table->dropIndex(['status', 'category_id']);
            $table->dropForeign(['category_id']);
            $table->dropColumn(['category_id', 'brand']);
            $table->index(['status', 'category']);
        });
    }
};
