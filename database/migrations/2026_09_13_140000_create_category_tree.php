<?php

use App\Catalog\CatalogSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The category tree and the per-listing attributes that hang off it.
 *
 * The tree is populated here rather than in a seeder because it is reference
 * data, not sample data: the application cannot serve a listing page without
 * it, so every environment that runs migrations needs it, including the test
 * database that is rebuilt from scratch on every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parent_id')->nullable()
                ->constrained('categories')
                // A department cannot be deleted out from under its children
                // by accident. Removing a branch is a deliberate act that has
                // to deal with its listings first.
                ->restrictOnDelete();

            // Unique so it can address a category on its own in a URL.
            // Duplicate names across branches are expected, and CatalogSync
            // disambiguates them rather than the tree avoiding them.
            $table->string('slug', 120)->unique();

            // Every ancestor's slug joined by slashes. The column the whole
            // browse experience is built on: one indexed LIKE returns a
            // department and all forty of its leaves.
            $table->string('path', 400)->unique();

            $table->string('name', 120);

            // Derivable from path, stored anyway: it is read on every render
            // to indent the tree, and counting slashes in SQL to sort by
            // depth is worse than a column.
            $table->unsignedTinyInteger('depth');

            // Sort order among siblings, taken from the order in the taxonomy
            // file, so the shop is arranged the way that file reads.
            $table->unsignedSmallInteger('position')->default(0);

            // Only leaves can be listed in. "Guitars" tells a buyer nothing,
            // and the point of a tree this deep is that the leaves are
            // specific enough for the filters to mean something.
            $table->boolean('is_leaf')->default(false);

            $table->timestamps();

            $table->index(['parent_id', 'position']);
        });

        Schema::create('listing_attributes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();

            // The attribute key from App\Catalog\Facets, e.g. 'vinyl_speed'.
            $table->string('name', 60);

            // The chosen option, stored as its display text rather than a
            // code. The options are a closed list validated on save, so the
            // text IS the identity, and storing it this way means a facet
            // count can be read straight out of the database without a
            // lookup table that would have to be kept in step with the PHP.
            $table->string('value', 120);

            $table->timestamps();

            // One value per attribute per listing. A guitar has one body
            // shape, and the database should be the thing that says so.
            $table->unique(['listing_id', 'name']);

            // Counting how many listings have each value is the single query
            // behind every filter panel on the site, so it gets a covering
            // index in the order it is read: narrow by attribute, group by
            // value, join back on listing.
            $table->index(['name', 'value', 'listing_id'], 'listing_attributes_facet_index');
        });

        // Reference data, not sample data. See the class comment.
        app(CatalogSync::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_attributes');
        Schema::dropIfExists('categories');
    }
};
