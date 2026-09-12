<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Search\ListingIndex;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes one listing into the search index, off the request.
 *
 * Doing this inline meant every save waited on Elasticsearch, and the write
 * asks the cluster to make the document searchable before returning, which
 * is the slowest way to index on purpose. That wait is worth having, but
 * not on a request a person is sitting in front of: here the worker waits
 * instead, and the seller gets their response immediately.
 */
class IndexListing implements ShouldQueue
{
    use Queueable;

    /**
     * Laravel serialises the model as an id and refetches it when the job
     * runs, which is what we want: the index gets whatever the listing is
     * at that moment, not whatever it was when the job was queued.
     */
    public function __construct(public Listing $listing) {}

    /**
     * A listing deleted between the save and the job running has nothing to
     * index, and RemoveListingFromIndex will have been queued behind this
     * one anyway. Dropping the job quietly is the right outcome.
     */
    public bool $deleteWhenMissingModels = true;

    public function handle(ListingIndex $index): void
    {
        if (! config('elasticsearch.enabled')) {
            return;
        }

        $this->listing->loadMissing('seller');

        $index->index($this->listing);
    }
}
