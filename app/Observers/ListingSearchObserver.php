<?php

namespace App\Observers;

use App\Models\Listing;
use App\Search\ListingIndex;

/**
 * Keeps the search index in step with the listings table.
 *
 * Writing synchronously rather than through a queue is a deliberate choice
 * at this size: there is no queue worker in the stack, the write is a single
 * small document, and ListingIndex swallows its own failures so a cluster
 * being down can never stop a seller saving a listing. A queued job is the
 * right answer once indexing volume makes the added latency measurable.
 */
class ListingSearchObserver
{
    public function __construct(private readonly ListingIndex $index) {}

    public function saved(Listing $listing): void
    {
        // seller_username is part of the document, and on a freshly created
        // listing the relation has not been loaded yet.
        $listing->loadMissing('seller');

        $this->index->index($listing);
    }

    public function deleted(Listing $listing): void
    {
        $this->index->remove($listing->id);
    }
}
