<?php

namespace App\Console\Commands;

use App\Catalog\CatalogSync;
use Illuminate\Console\Command;

/**
 * Reapplies App\Catalog\Taxonomy to the categories table.
 *
 * Run after editing the taxonomy. Safe to run at any time and safe to run
 * twice, which is what lets it go in a deploy script without anyone having to
 * remember whether the tree changed this release.
 */
class SyncCatalog extends Command
{
    protected $signature = 'catalog:sync';

    protected $description = 'Rebuild the category tree from the taxonomy definition';

    public function handle(CatalogSync $sync): int
    {
        $result = $sync->run();

        $this->info("Categories created: {$result['created']}");
        $this->info("Categories updated: {$result['updated']}");

        if ($result['orphaned'] !== []) {
            // Not deleted, and not an error. A category the taxonomy no
            // longer mentions may still have listings in it, and deciding
            // what happens to them is a person's job, not a sync's.
            $this->newLine();
            $this->warn('These categories are in the database but no longer in the taxonomy:');

            foreach ($result['orphaned'] as $path) {
                $this->line("  {$path}");
            }

            $this->newLine();
            $this->line('Nothing was deleted. Move any listings out of them first, then remove them by hand.');
        }

        return self::SUCCESS;
    }
}
