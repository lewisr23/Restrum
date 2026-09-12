<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores where a file is, not what its URL happens to be.
 *
 * The url column baked the local disk's public path into every row, so
 * moving media to object storage would have meant rewriting all of them,
 * and deleting a file meant reconstructing the storage path by stripping
 * the "/storage/" prefix back off the URL. Keeping the path instead makes
 * the URL a function of whichever disk is configured at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_media', function (Blueprint $table) {
            $table->string('path')->after('media_type')->nullable();
        });

        // Existing rows hold URLs written by Storage::url() on the public
        // disk, which is exactly that prefix followed by the path.
        DB::table('listing_media')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('listing_media')
                    ->where('id', $row->id)
                    ->update(['path' => ltrim(str_replace('/storage/', '', $row->url), '/')]);
            }
        });

        Schema::table('listing_media', function (Blueprint $table) {
            $table->string('path')->nullable(false)->change();
            $table->dropColumn('url');
        });
    }

    public function down(): void
    {
        Schema::table('listing_media', function (Blueprint $table) {
            $table->string('url')->after('media_type')->nullable();
        });

        DB::table('listing_media')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('listing_media')
                    ->where('id', $row->id)
                    ->update(['url' => '/storage/'.$row->path]);
            }
        });

        Schema::table('listing_media', function (Blueprint $table) {
            $table->string('url')->nullable(false)->change();
            $table->dropColumn('path');
        });
    }
};
