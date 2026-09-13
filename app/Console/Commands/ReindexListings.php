<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Search\ListingIndex;
use Illuminate\Console\Command;

class ReindexListings extends Command
{
    protected $signature = 'search:reindex
        {--fresh : Drop the index and recreate it, which is how a mapping change gets applied}
        {--chunk=500 : Listings per bulk request}';

    protected $description = 'Build the Elasticsearch listings index from the database';

    public function handle(ListingIndex $index): int
    {
        if (! config('elasticsearch.enabled')) {
            $this->error('Search is disabled. Set ELASTICSEARCH_ENABLED=true to use this command.');

            return self::FAILURE;
        }

        $fresh = (bool) $this->option('fresh');
        $chunk = max(1, (int) $this->option('chunk'));

        // Mappings are immutable once a field is indexed, so a changed
        // analyzer means a new index rather than an update in place.
        $this->info($fresh
            ? "Recreating index [{$index->name()}]..."
            : "Ensuring index [{$index->name()}] exists...");

        try {
            $index->create(dropExisting: $fresh);
        } catch (\Throwable $e) {
            $this->error('Could not create the index: '.$e->getMessage());

            return self::FAILURE;
        }

        $total = Listing::count();

        if ($total === 0) {
            $this->info('No listings to index.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $indexed = 0;

        try {
            // Category and attributes as well as the seller: every document
            // carries them now, and without eager loading a bulk index of 500
            // listings is 1500 extra queries.
            Listing::with('seller', 'category', 'attributeValues')->chunkById($chunk, function ($listings) use ($index, $bar, &$indexed) {
                $indexed += $index->bulkIndex($listings);
                $bar->advance($listings->count());
            });
        } catch (\Throwable $e) {
            $bar->finish();
            $this->newLine(2);
            $this->error('Indexing failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        // Without this the documents are written but not yet searchable, and
        // a reindex that appears to have done nothing is a confusing thing to
        // hand someone.
        $index->refresh();

        $this->info("Indexed {$indexed} listing(s) into [{$index->name()}].");

        return self::SUCCESS;
    }
}
