<?php

namespace App\Observers;

use App\Jobs\IndexListing;
use App\Jobs\RemoveListingFromIndex;
use App\Models\Listing;

/**
 * Keeps the search index in step with the listings table.
 *
 * Dispatches rather than indexing inline, so a save returns without waiting
 * on Elasticsearch. The index is therefore eventually consistent with the
 * database, by however long the queue is: acceptable here, because a
 * listing being searchable a moment late is not something anyone can
 * perceive, while the request being slower is.
 *
 * The enabled check is deliberately here as well as inside the jobs. There
 * is no point queueing work that will do nothing when it runs.
 */
class ListingSearchObserver
{
    public function saved(Listing $listing): void
    {
        if (! config('elasticsearch.enabled')) {
            return;
        }

        IndexListing::dispatch($listing);
    }

    public function deleted(Listing $listing): void
    {
        if (! config('elasticsearch.enabled')) {
            return;
        }

        RemoveListingFromIndex::dispatch($listing->id);
    }
}
